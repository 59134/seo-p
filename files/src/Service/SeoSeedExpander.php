<?php

namespace App\Service;

use App\Entity\SeoPage;
use App\Entity\SeoSeed;
use App\Repository\SeoSeedRepository;
use Doctrine\ORM\EntityManagerInterface;

class SeoSeedExpander
{
    private int $lastArchivedMalformedCount = 0;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SeoSeedRepository $seoSeedRepository
    ) {
    }

    /**
     * @return array<int, array{seed: SeoSeed, created: bool, updated: bool, keyword: string}>
     */
    public function createOrFindSeedsFromPageKeywords(SeoSeed $sourceSeed): array
    {
        $items = [];
        $pageKeywordLines = $this->pageKeywordLines($sourceSeed);
        $this->lastArchivedMalformedCount = $this->archiveMalformedChildSeeds($sourceSeed, $pageKeywordLines);

        foreach ($pageKeywordLines as $line) {
            $pageData = $this->parsePageKeywordLine($line, $sourceSeed);

            if (!$pageData['mainKeyword']) {
                continue;
            }

            $seed = $this->findExistingSeed($pageData['mainKeyword'], $sourceSeed->getLocale());
            $created = false;

            if (!$seed) {
                $seed = new SeoSeed();
                $this->entityManager->persist($seed);
                $created = true;
            }

            $updated = false;
            if ($created || $this->shouldRefreshChildSeed($seed, $sourceSeed, $pageData['mainKeyword'])) {
                $dataBeforeRefresh = $created ? null : $this->seedGenerationData($seed);
                $this->applyPageDataToSeed($seed, $sourceSeed, $pageData);
                $updated = $created || $dataBeforeRefresh !== $this->seedGenerationData($seed);
            }

            $items[] = [
                'seed' => $seed,
                'created' => $created,
                'updated' => $updated,
                'keyword' => $pageData['mainKeyword'],
            ];
        }

        $this->entityManager->flush();

        return $items;
    }

    public function getLastArchivedMalformedCount(): int
    {
        return $this->lastArchivedMalformedCount;
    }

    public function seedAlreadyHasPage(SeoSeed $seed): bool
    {
        return null !== $this->findPageForSeed($seed);
    }

    public function findPageForSeed(SeoSeed $seed): ?SeoPage
    {
        return $this->entityManager->getRepository(SeoPage::class)->findActiveForSeed($seed);
    }

    /**
     * @return string[]
     */
    private function pageKeywordLines(SeoSeed $sourceSeed): array
    {
        $lines = [];

        foreach ($sourceSeed->getPageKeywords() as $line) {
            foreach ($this->splitCombinedPageKeywordLine($line, $sourceSeed) as $splitLine) {
                $lines[] = $splitLine;
            }
        }

        $uniqueLines = [];
        foreach ($lines as $line) {
            $uniqueLines[$this->normalize($line)] = $line;
        }

        return array_values($uniqueLines);
    }

    /**
     * @return string[]
     */
    private function splitCombinedPageKeywordLine(string $line, SeoSeed $sourceSeed): array
    {
        $line = SeoSeed::cleanPlainTextLine($line);

        if ($line === '') {
            return [];
        }

        $sourceKeywordService = $this->sourceKeywordWithoutCity($sourceSeed);

        if (!$sourceKeywordService || str_contains($line, '|')) {
            return [$line];
        }

        $parts = preg_split(
            '/\s+(?=' . preg_quote($sourceKeywordService, '/') . '(?:\s|$))/iu',
            $line
        ) ?: [$line];
        $parts = array_map(static fn (string $part): string => SeoSeed::cleanPlainTextLine($part), $parts);

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * Format simple:
     * mot cle principal
     *
     * Format avance:
     * mot cle principal | service | ville | departement | intention
     *
     * @return array{
     *     mainKeyword: string,
     *     service: string,
     *     city: ?string,
     *     department: ?string,
     *     secondaryKeywords: array<int, string>,
     *     intent: string,
     *     notes: ?string
     * }
     */
    private function parsePageKeywordLine(string $line, SeoSeed $sourceSeed): array
    {
        $line = SeoSeed::cleanPlainTextLine($line);
        $parts = array_map(static fn (string $part): string => SeoSeed::cleanPlainTextLine($part), explode('|', $line));
        $mainKeyword = $parts[0] ?? '';
        $service = $parts[1] ?? null;
        $city = $parts[2] ?? null;
        $department = $parts[3] ?? null;
        $intent = $parts[4] ?? null;

        if (!$service || !$city) {
            $inferred = $this->inferServiceAndCity($mainKeyword, $sourceSeed);
            $service = $service ?: $inferred['service'];
            $city = $city ?: $inferred['city'];
        }

        $department = $department ?: $sourceSeed->getDepartment();
        $city = $city ?: $sourceSeed->getCity();
        $secondaryKeywords = $this->buildSecondaryKeywords($sourceSeed, $mainKeyword, $city);

        $intent = $intent ?: sprintf('Repondre a la recherche "%s" avec une page specifique, utile et locale.', $mainKeyword);

        if ($sourceSeed->getIntent()) {
            $intent .= "\n\nContexte du seed source (ne pas transposer ses faits locaux): " . $sourceSeed->getIntent();
        }

        // Preserve locations, references and URLs: they are not interchangeable facts.
        $sourceNotes = (string) $sourceSeed->getNotes();
        $notes = trim(sprintf(
            "%s\n\nSeed cree automatiquement depuis le seed source%s \"%s\".",
            $sourceNotes,
            $sourceSeed->getId() ? ' #' . $sourceSeed->getId() : '',
            (string) $sourceSeed->getMainKeyword()
        ));

        return [
            'mainKeyword' => $mainKeyword,
            'service' => $service ?: ($sourceSeed->getService() ?: $mainKeyword),
            'city' => $city ?: $sourceSeed->getCity(),
            'department' => $department,
            'secondaryKeywords' => $secondaryKeywords,
            'intent' => $intent,
            'notes' => $notes ?: null,
        ];
    }

    /**
     * @return array{service: ?string, city: ?string}
     */
    private function inferServiceAndCity(string $mainKeyword, SeoSeed $sourceSeed): array
    {
        $mainKeyword = SeoSeed::cleanPlainTextLine($mainKeyword);
        $service = $sourceSeed->getService();
        $city = $sourceSeed->getCity();
        $sourceKeywordService = $this->sourceKeywordWithoutCity($sourceSeed);

        if ($city && $this->containsNormalized($mainKeyword, $city)) {
            $candidateService = trim((string) preg_replace('/\b' . preg_quote($city, '/') . '\b/iu', '', $mainKeyword));
            $candidateService = trim(preg_replace('/\s+/', ' ', $candidateService) ?: '');

            if ($candidateService !== '') {
                $service = $candidateService;
            }

            return ['service' => $service, 'city' => $city];
        }

        if ($sourceKeywordService && $this->startsWithNormalized($mainKeyword, $sourceKeywordService)) {
            $candidateCity = $this->trailingTextAfterPrefix($mainKeyword, $sourceKeywordService);

            if ($candidateCity !== '') {
                return [
                    'service' => $service ?: $sourceKeywordService,
                    'city' => $candidateCity,
                ];
            }
        }

        if ($service && str_starts_with($this->normalize($mainKeyword), $this->normalize($service))) {
            $candidateCity = trim(substr($mainKeyword, strlen($service)));

            if ($candidateCity !== '') {
                $city = $candidateCity;
            }

            return ['service' => $service, 'city' => $city];
        }

        if (preg_match('/^(.+?)\s+(?:a|à|sur|dans)\s+(.+)$/iu', $mainKeyword, $matches)) {
            return [
                'service' => trim($matches[1]),
                'city' => trim($matches[2]),
            ];
        }

        $trailingCity = $this->splitTrailingCityCandidate($mainKeyword, $sourceKeywordService);

        if ($trailingCity) {
            return $trailingCity;
        }

        return ['service' => $service, 'city' => $city];
    }

    /**
     * @param array{
     *     mainKeyword: string,
     *     service: string,
     *     city: ?string,
     *     department: ?string,
     *     secondaryKeywords: array<int, string>,
     *     intent: string,
     *     notes: ?string
     * } $pageData
     */
    private function applyPageDataToSeed(SeoSeed $seed, SeoSeed $sourceSeed, array $pageData): void
    {
        $seed
            ->setMainKeyword($pageData['mainKeyword'])
            ->setService($pageData['service'])
            ->setServicePageUrl($sourceSeed->getServicePageUrl())
            ->setServicePageLabel($sourceSeed->getServicePageLabel())
            ->setCity($pageData['city'])
            ->setDepartment($pageData['department'])
            ->setLocale($sourceSeed->getLocale())
            ->setSecondaryKeywords($pageData['secondaryKeywords'])
            ->setIntent($pageData['intent'])
            ->setNotes($pageData['notes'])
            ->setBusinessValue($sourceSeed->getBusinessValue())
            ->setPriority($sourceSeed->getPriority())
            ->setClaudeModelPreference($sourceSeed->getClaudeModelPreference())
            ->setValid(true);

        $seed->refreshDataCompletenessScore();
    }

    private function findExistingSeed(string $mainKeyword, string $locale): ?SeoSeed
    {
        $mainKeyword = SeoSeed::cleanPlainTextLine($mainKeyword);
        $seed = $this->seoSeedRepository->findOneBy([
            'mainKeyword' => $mainKeyword,
            'locale' => $locale,
        ]);

        if ($seed) {
            return $seed;
        }

        $normalizedKeyword = $this->normalize($mainKeyword);

        foreach ($this->seoSeedRepository->findBy(['locale' => $locale]) as $candidate) {
            if ($this->normalize((string) $candidate->getMainKeyword()) === $normalizedKeyword) {
                return $candidate;
            }
        }

        return null;
    }

    private function shouldRefreshChildSeed(SeoSeed $seed, SeoSeed $sourceSeed, string $mainKeyword): bool
    {
        if ($seed === $sourceSeed || ($seed->getId() && $seed->getId() === $sourceSeed->getId())) {
            return false;
        }

        $notes = (string) $seed->getNotes();
        $sourceId = $sourceSeed->getId();
        $sourceKeyword = (string) $sourceSeed->getMainKeyword();

        if ($sourceId && str_contains($notes, 'seed source #' . $sourceId)) {
            return true;
        }

        if ($sourceKeyword !== '' && str_contains($notes, 'seed source') && str_contains($notes, $sourceKeyword)) {
            return true;
        }

        if ($this->normalize((string) $seed->getMainKeyword()) === $this->normalize($mainKeyword)
            && $this->normalize((string) $seed->getCity()) === $this->normalize((string) $sourceSeed->getCity())) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string, bool|int|string|null|array<int, string>>
     */
    private function seedGenerationData(SeoSeed $seed): array
    {
        return [
            'mainKeyword' => $seed->getMainKeyword(),
            'service' => $seed->getService(),
            'servicePageUrl' => $seed->getServicePageUrl(),
            'servicePageLabel' => $seed->getServicePageLabel(),
            'city' => $seed->getCity(),
            'department' => $seed->getDepartment(),
            'locale' => $seed->getLocale(),
            'secondaryKeywords' => $seed->getSecondaryKeywords(),
            'intent' => $seed->getIntent(),
            'notes' => $seed->getNotes(),
            'businessValue' => $seed->getBusinessValue(),
            'priority' => $seed->getPriority(),
            'claudeModelPreference' => $seed->getClaudeModelPreference(),
            'dataCompletenessScore' => $seed->getDataCompletenessScore(),
            'valid' => $seed->isValid(),
        ];
    }

    /**
     * @param string[] $expectedKeywords
     */
    private function archiveMalformedChildSeeds(SeoSeed $sourceSeed, array $expectedKeywords): int
    {
        if (!$expectedKeywords) {
            return 0;
        }

        $expectedNormalized = array_values(array_unique(array_map(
            fn (string $keyword): string => $this->normalize($keyword),
            $expectedKeywords
        )));
        $archived = 0;

        foreach ($this->seoSeedRepository->findBy(['locale' => $sourceSeed->getLocale()]) as $candidate) {
            if ($candidate === $sourceSeed || ($candidate->getId() && $candidate->getId() === $sourceSeed->getId())) {
                continue;
            }

            if (!$this->isMalformedCombinedChildSeed($candidate, $sourceSeed, $expectedNormalized)) {
                continue;
            }

            $candidate->setValid(false);
            $candidate->setNotes(trim(sprintf(
                "%s\n\nSeed archive automatiquement: ancien seed combine detecte lors de la regeneration depuis le seed source #%s.",
                (string) $candidate->getNotes(),
                (string) $sourceSeed->getId()
            )));

            $page = $this->findPageForSeed($candidate);

            if ($page) {
                $page
                    ->setStatus(SeoPage::STATUS_ARCHIVED)
                    ->setIndexable(false)
                    ->setQualityFlags(array_values(array_unique(array_merge(
                        $page->getQualityFlags(),
                        ['Page archivee automatiquement: seed combine invalide.']
                    ))));
            }

            $archived++;
        }

        return $archived;
    }

    /**
     * @param string[] $expectedNormalized
     */
    private function isMalformedCombinedChildSeed(SeoSeed $candidate, SeoSeed $sourceSeed, array $expectedNormalized): bool
    {
        $candidateKeyword = $this->normalize((string) $candidate->getMainKeyword());

        if ($candidateKeyword === '' || in_array($candidateKeyword, $expectedNormalized, true)) {
            return false;
        }

        $matches = 0;
        foreach ($expectedNormalized as $expectedKeyword) {
            if ($expectedKeyword !== '' && str_contains($candidateKeyword, $expectedKeyword)) {
                $matches++;
            }
        }

        if ($matches < 2) {
            return false;
        }

        $notes = (string) $candidate->getNotes();
        $sourceKeyword = (string) $sourceSeed->getMainKeyword();

        return ($sourceSeed->getId() && str_contains($notes, 'seed source #' . $sourceSeed->getId()))
            || ($sourceKeyword !== '' && str_contains($notes, 'seed source') && str_contains($notes, $sourceKeyword))
            || $this->normalize((string) $candidate->getService()) === $this->normalize((string) $sourceSeed->getService());
    }

    /**
     * @return string[]
     */
    private function buildSecondaryKeywords(SeoSeed $sourceSeed, string $mainKeyword, ?string $targetCity): array
    {
        $keywords = [];

        foreach ($sourceSeed->getSecondaryKeywords() as $keyword) {
            $keyword = $this->replaceSourceCity($keyword, $sourceSeed, $targetCity);

            if ($keyword === '' || $this->normalize($keyword) === $this->normalize($mainKeyword)) {
                continue;
            }

            $keywords[] = $keyword;
        }

        return array_values(array_unique($keywords));
    }

    private function replaceSourceCity(?string $value, SeoSeed $sourceSeed, ?string $targetCity): string
    {
        $value = SeoSeed::cleanPlainTextBlock($value) ?: '';
        $sourceCity = $sourceSeed->getCity();
        $targetCity = SeoSeed::cleanPlainTextLine($targetCity);

        if ($value === '' || !$sourceCity || $targetCity === '' || $this->normalize($sourceCity) === $this->normalize($targetCity)) {
            return $value;
        }

        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($sourceCity, '/') . '(?![\p{L}\p{N}])/iu';

        return preg_replace($pattern, $targetCity, $value) ?: $value;
    }

    private function sourceKeywordWithoutCity(SeoSeed $sourceSeed): ?string
    {
        $mainKeyword = $sourceSeed->getMainKeyword();
        $city = $sourceSeed->getCity();

        if (!$mainKeyword || !$city || !$this->containsNormalized($mainKeyword, $city)) {
            return null;
        }

        $service = trim((string) preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($city, '/') . '(?![\p{L}\p{N}])/iu', '', $mainKeyword));
        $service = SeoSeed::cleanPlainTextLine($service);

        return $service !== '' ? $service : null;
    }

    private function startsWithNormalized(string $value, string $prefix): bool
    {
        return str_starts_with($this->normalize($value), $this->normalize($prefix));
    }

    private function trailingTextAfterPrefix(string $value, string $prefix): string
    {
        $value = SeoSeed::cleanPlainTextLine($value);
        $prefix = SeoSeed::cleanPlainTextLine($prefix);

        if (str_starts_with($value, $prefix)) {
            return SeoSeed::cleanPlainTextLine(substr($value, strlen($prefix)));
        }

        $normalizedPrefix = $this->normalize($prefix);
        $words = preg_split('/\s+/', $value) ?: [];
        $candidate = '';

        foreach ($words as $word) {
            $nextCandidate = trim($candidate . ' ' . $word);

            if ($this->normalize($nextCandidate) === $normalizedPrefix) {
                return SeoSeed::cleanPlainTextLine(substr($value, strlen($nextCandidate)));
            }

            $candidate = $nextCandidate;
        }

        return '';
    }

    /**
     * @return array{service: string, city: string}|null
     */
    private function splitTrailingCityCandidate(string $mainKeyword, ?string $sourceKeywordService): ?array
    {
        $words = preg_split('/\s+/', SeoSeed::cleanPlainTextLine($mainKeyword)) ?: [];

        if (count($words) < 2) {
            return null;
        }

        $start = max(1, count($words) - 4);

        for ($index = $start; $index < count($words); $index++) {
            $candidateCity = implode(' ', array_slice($words, $index));
            $candidateService = implode(' ', array_slice($words, 0, $index));

            if (!$this->looksLikeCityCandidate($candidateCity)) {
                continue;
            }

            $service = $candidateService;

            if ($sourceKeywordService && $this->normalize($candidateService) === $this->normalize($sourceKeywordService)) {
                $service = $sourceKeywordService;
            }

            return [
                'service' => SeoSeed::cleanPlainTextLine($service),
                'city' => SeoSeed::cleanPlainTextLine($candidateCity),
            ];
        }

        return null;
    }

    private function looksLikeCityCandidate(string $candidate): bool
    {
        $candidate = SeoSeed::cleanPlainTextLine($candidate);

        if ($candidate === '') {
            return false;
        }

        $words = preg_split('/\s+/', $candidate) ?: [];
        $connectors = ['a', 'au', 'aux', 'd', 'de', 'des', 'du', 'en', 'la', 'le', 'les', 'l', 'sur', 'sous'];
        $hasCitySignal = false;

        foreach ($words as $word) {
            $cleanWord = trim($word, " \t\n\r\0\x0B'’.-");
            $normalized = $this->normalize($cleanWord);

            if ($normalized === '' || in_array($normalized, $connectors, true)) {
                continue;
            }

            if (preg_match('/^\p{Lu}/u', $cleanWord) || str_contains($word, '-') || str_contains($word, "'") || str_contains($word, '’')) {
                $hasCitySignal = true;
                continue;
            }

            return false;
        }

        return $hasCitySignal;
    }

    private function containsNormalized(string $haystack, string $needle): bool
    {
        return str_contains($this->normalize($haystack), $this->normalize($needle));
    }

    private function normalize(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        $value = strtolower((string) $value);

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value) ?: '');
    }
}
