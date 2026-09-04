<?php

namespace App\Service;

use App\Entity\SeoSeed;
use App\Repository\SeoPageRepository;

class SeoEditorialAdvisor
{
    public const FAQ_TOPICS = [
        'preparation' => 'Informations et preparations utiles avant la demande',
        'choix' => 'Choisir entre les prestations ou options confirmees',
        'deroulement' => 'Deroulement reel de la prestation',
        'contraintes' => 'Limites, exclusions et contraintes documentees',
        'organisation' => 'Organisation pratique, uniquement selon les faits disponibles',
        'suivi' => 'Suivi et entretien lorsqu ils sont effectivement proposes',
        'adequation' => 'A quels besoins cette prestation repond ou ne repond pas',
    ];

    public function __construct(private SeoPageRepository $pages)
    {
    }

    public static function faqPlan(SeoSeed $seed): array
    {
        $topics = self::FAQ_TOPICS;
        $key = $seed->getLocale() . '|' . $seed->getMainKeyword();
        uksort($topics, static fn (string $a, string $b): int => strcmp(hash('sha256', $key . $a), hash('sha256', $key . $b)));
        return array_slice($topics, 0, 3, true);
    }

    /** Advisory lexical checks, not semantic scores and never an automatic publication ban. */
    public function warnings(array $payload, SeoSeed $seed): array
    {
        $warnings = [];
        foreach ($this->pages->findForAntiDuplicationPrompt($seed, 12) as $other) {
            if ($other->getSeed() === $seed || ($seed->getId() && $other->getSeed()?->getId() === $seed->getId())) {
                continue;
            }
            $cities = array_filter([$seed->getCity(), $other->getSeed()?->getCity()]);
            $body = self::body($payload['intro'] ?? '', $payload['sections'] ?? []);
            $otherBody = self::body($other->getIntro() ?? '', $other->getContent());
            $similarity = self::textualSimilarity($body, $otherBody, $cities);
            if ($similarity >= 0.8) {
                $warnings[] = sprintf('Relecture editorialement recommandee: forte reprise textuelle avec /%s (comparaison lexicale, pas une mesure semantique).', $other->getSlug());
            }
            $questions = self::questions($payload['faq'] ?? [], $cities);
            $otherQuestions = self::questions($other->getFaq(), $cities);
            if (count($questions) >= 3 && count(array_intersect($questions, $otherQuestions)) >= 3) {
                $warnings[] = sprintf('FAQ: au moins trois questions identiques hors noms de villes avec /%s. Verifier si des sujets differents seraient plus utiles.', $other->getSlug());
            }
        }
        return array_values(array_unique($warnings));
    }

    public static function textualSimilarity(string $a, string $b, array $cities = []): float
    {
        $a = explode(' ', self::normalize($a, $cities));
        $b = explode(' ', self::normalize($b, $cities));
        if (count($a) < 80 || count($b) < 80) {
            return 0.0;
        }
        $left = self::shingles($a);
        $right = self::shingles($b);
        return count(array_intersect_key($left, $right)) / max(1, count($left + $right));
    }

    private static function shingles(array $words): array
    {
        $shingles = [];
        for ($i = 0; $i <= count($words) - 5; $i++) {
            $shingles[implode(' ', array_slice($words, $i, 5))] = true;
        }
        return $shingles;
    }

    private static function normalize(string $value, array $cities): string
    {
        $value = mb_strtolower(SeoSeed::cleanPlainTextBlock($value) ?? '', 'UTF-8');
        foreach ($cities as $city) {
            $value = preg_replace('/(?<![\p{L}\p{N}])' . preg_quote(mb_strtolower($city, 'UTF-8'), '/') . '(?![\p{L}\p{N}])/u', ' LOCALITE ', $value) ?? $value;
        }
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '');
    }

    private static function questions(array $faq, array $cities): array
    {
        $questions = [];
        foreach ($faq as $item) {
            if (is_array($item) && is_string($item['question'] ?? null)) {
                $question = self::normalize($item['question'], $cities);
                if ($question !== '') {
                    $questions[] = $question;
                }
            }
        }
        return array_values(array_unique($questions));
    }

    private static function body(string $intro, array $sections): string
    {
        $parts = [$intro];
        foreach ($sections as $section) {
            if (is_array($section) && is_string($section['body'] ?? null)) {
                $parts[] = $section['body'];
            }
        }
        return implode(' ', $parts);
    }
}
