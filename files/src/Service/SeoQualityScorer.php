<?php

namespace App\Service;

use App\Entity\SeoPage;
use App\Entity\SeoSeed;

class SeoQualityScorer
{
    public function __construct(private SeoEditorialAdvisor $editorialAdvisor)
    {
    }

    public function score(array $payload, SeoSeed $seed, bool $withEditorialChecks = true): array
    {
        $flags = $payload['quality_flags'] ?? [];
        $missing = $payload['missing_data'] ?? [];
        $issues = SeoPublicationPolicy::classify($missing);
        $score = 0;

        $score += $this->hasText($payload['title'] ?? null, 35, 70) ? 10 : 0;
        $score += $this->hasText($payload['meta_description'] ?? null, 90, 180) ? 10 : 0;
        $score += $this->hasText($payload['h1'] ?? null, 20, 180) ? 10 : 0;
        $score += $this->hasText($payload['intro'] ?? null, 180, 900) ? 10 : 0;

        $sections = $payload['sections'] ?? [];
        $score += is_array($sections) && count($sections) >= 3 ? 10 : 0;
        $score += $this->sectionsLookSubstantial($sections) ? 10 : 0;

        $faq = $payload['faq'] ?? [];
        $score += is_array($faq) && count($faq) >= 3 ? 10 : 0;

        $score += $seed->getCity() || $seed->getDepartment() ? 10 : 0;
        $score += $seed->getDataCompletenessScore() >= 65 ? 10 : 0;
        $score += count($missing) === 0 ? 10 : 0;

        if (!$seed->getCity() && !$seed->getDepartment()) {
            $flags[] = 'Contexte local faible: aucune ville ni departement dans le seed.';
        }

        if (count($missing) > 0) {
            $flags[] = $issues['blocking']
                ? 'Des donnees critiques empechent la publication.'
                : ($issues['review']
                    ? 'Une relecture editoriale doit etre confirmee avant publication.'
                    : 'Des precisions facultatives restent a verifier, sans bloquer la publication.');
        }

        if (($payload['indexation_recommendation'] ?? 'review') !== 'index') {
            $flags[] = 'Claude recommande une revue ou un noindex.';
        }

        $score = max(0, $score - $this->keywordUsagePenalty($payload, $seed, $flags));
        if ($withEditorialChecks) {
            $flags = array_merge($flags, $this->editorialAdvisor->warnings($payload, $seed));
        }

        $indexable = $score >= 75
            && $issues['blocking'] === []
            && $issues['review'] === []
            && ($payload['indexation_recommendation'] ?? 'review') === 'index';

        return [
            'score' => min(100, $score),
            'flags' => array_values(array_unique(array_filter($flags))),
            'missing_data' => array_values(array_unique(array_filter($missing))),
            'indexable' => $indexable,
        ];
    }

    /**
     * Recalcule le score depuis le contenu réellement sauvegardé dans l'admin.
     *
     * @return array{score: int, flags: array, missing_data: array, indexable: bool}
     */
    public function scorePage(SeoPage $page, bool $withEditorialChecks = true): array
    {
        $seed = $page->getSeed();

        if (!$seed) {
            return [
                'score' => 0,
                'flags' => ['Aucun seed SEO lie a cette page.'],
                'missing_data' => $page->getMissingData(),
                'indexable' => false,
            ];
        }

        return $this->score([
            'title' => $page->getTitle(),
            'meta_description' => $page->getMetaDescription(),
            'h1' => $page->getH1(),
            'intro' => $page->getIntro(),
            'sections' => $page->getContent(),
            'faq' => $page->getFaq(),
            'quality_flags' => [],
            'missing_data' => $page->getMissingData(),
            'indexation_recommendation' => 'index',
        ], $seed, $withEditorialChecks);
    }

    private function hasText(?string $value, int $min, int $max): bool
    {
        if (!$value) {
            return false;
        }

        $cleanValue = trim(strip_tags($value));
        $length = function_exists('mb_strlen') ? mb_strlen($cleanValue) : strlen($cleanValue);

        return $length >= $min && $length <= $max;
    }

    private function sectionsLookSubstantial(array $sections): bool
    {
        if ($sections === []) {
            return false;
        }

        foreach ($sections as $section) {
            if (!is_array($section)) {
                return false;
            }

            if (!$this->hasText($section['h2'] ?? null, 10, 160)) {
                return false;
            }

            if (!$this->hasText($section['body'] ?? null, 140, 1200)) {
                return false;
            }
        }

        return true;
    }

    private function keywordUsagePenalty(array $payload, SeoSeed $seed, array &$flags): int
    {
        $sections = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
        $h2Texts = [];
        $bodyTexts = [
            (string) ($payload['title'] ?? ''),
            (string) ($payload['meta_description'] ?? ''),
            (string) ($payload['h1'] ?? ''),
            (string) ($payload['intro'] ?? ''),
        ];

        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $h2Texts[] = (string) ($section['h2'] ?? '');
            $bodyTexts[] = (string) ($section['h2'] ?? '');
            $bodyTexts[] = (string) ($section['body'] ?? '');
        }

        foreach (($payload['faq'] ?? []) as $faq) {
            if (!is_array($faq)) {
                continue;
            }

            $bodyTexts[] = (string) ($faq['question'] ?? '');
            $bodyTexts[] = (string) ($faq['answer'] ?? '');
        }

        $penalty = 0;
        $primaryKeyword = $this->normalizeKeyword((string) ($seed->getMainKeyword() ?: ($payload['h1'] ?? '')));

        if ($primaryKeyword !== '') {
            $primaryH2Count = 0;
            foreach ($h2Texts as $h2) {
                $normalizedH2 = $this->normalizeKeyword($h2);
                if ($normalizedH2 === $primaryKeyword || str_contains($normalizedH2, $primaryKeyword)) {
                    $primaryH2Count++;
                }
            }

            if ($primaryH2Count > 1) {
                $flags[] = 'Trop de H2 reprennent le mot clé principal. Un seul H2 maximum devrait le reprendre tel quel.';
                $penalty += 5;
            }
        }

        $secondaryKeywords = array_values(array_filter(
            array_map(fn (string $keyword): string => $this->normalizeKeyword($keyword), $seed->getSecondaryKeywords()),
            static fn (string $keyword): bool => $keyword !== ''
        ));

        if (!$secondaryKeywords) {
            return $penalty;
        }

        $secondaryKeywords = array_values(array_unique($secondaryKeywords));
        $h2SecondaryMatches = [];
        foreach ($h2Texts as $h2) {
            $normalizedH2 = $this->normalizeKeyword($h2);
            foreach ($secondaryKeywords as $secondaryKeyword) {
                if ($this->containsKeyword($normalizedH2, $secondaryKeyword)) {
                    $h2SecondaryMatches[$secondaryKeyword] = true;
                    break;
                }
            }
        }

        $expectedH2Matches = min(count($h2Texts), count($secondaryKeywords), 3);
        if ($expectedH2Matches > 0 && count($h2SecondaryMatches) < $expectedH2Matches) {
            $flags[] = 'Les H2 utilisent trop peu les mots clés secondaires du seed.';
            $penalty += 5;
        }

        $normalizedBody = $this->normalizeKeyword(implode(' ', $bodyTexts));
        $usedSecondaryKeywords = [];
        foreach ($secondaryKeywords as $secondaryKeyword) {
            if ($this->containsKeyword($normalizedBody, $secondaryKeyword)) {
                $usedSecondaryKeywords[$secondaryKeyword] = true;
            }
        }

        $expectedContentMatches = min(count($secondaryKeywords), max(1, (int) ceil(count($secondaryKeywords) * 0.35)));
        if (count($usedSecondaryKeywords) < $expectedContentMatches) {
            $flags[] = 'Le contenu integre trop peu de mots clés secondaires.';
            $penalty += 5;
        }

        return $penalty;
    }

    private function containsKeyword(string $text, string $keyword): bool
    {
        if ($keyword === '') {
            return false;
        }

        if (str_contains($text, $keyword)) {
            return true;
        }

        $keywordTokens = array_values(array_filter(explode(' ', $keyword), static fn (string $token): bool => strlen($token) > 3));

        if (count($keywordTokens) < 2) {
            return false;
        }

        $matchedTokens = 0;
        foreach ($keywordTokens as $token) {
            if (str_contains($text, $token)) {
                $matchedTokens++;
            }
        }

        return $matchedTokens >= min(2, count($keywordTokens));
    }

    private function normalizeKeyword(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $asciiText = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) : $text;
        $asciiText = strtolower((string) $asciiText);

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $asciiText) ?: '');
    }
}
