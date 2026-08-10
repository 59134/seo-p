<?php

namespace App\Service;

use App\Entity\SeoFact;
use App\Entity\SeoSeed;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;

class SeoJsonImporter
{
    public const SCOPE_ALL = 'all';
    public const SCOPE_FACTS = 'facts';
    public const SCOPE_SEEDS = 'seeds';

    private const VALID_FACT_TYPES = [
        SeoFact::TYPE_BUSINESS,
        SeoFact::TYPE_SERVICE,
        SeoFact::TYPE_LOCAL,
        SeoFact::TYPE_PROOF,
        SeoFact::TYPE_FORBIDDEN_CLAIM,
        SeoFact::TYPE_BRAND,
        SeoFact::TYPE_FAQ,
        SeoFact::TYPE_PRICING,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function importFile(string $file, string $scope = self::SCOPE_ALL, bool $update = false, bool $dryRun = false): array
    {
        if (!in_array($scope, [self::SCOPE_ALL, self::SCOPE_FACTS, self::SCOPE_SEEDS], true)) {
            throw new \InvalidArgumentException(sprintf('Type d import invalide: %s', $scope));
        }

        if (!is_file($file) || !is_readable($file)) {
            throw new \RuntimeException(sprintf('Fichier introuvable ou illisible: %s', $file));
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(sprintf('JSON invalide: %s', $exception->getMessage()), 0, $exception);
        }

        if (!is_array($data)) {
            throw new \RuntimeException('Le JSON doit contenir un objet racine.');
        }

        $client = is_string($data['client'] ?? null) ? $data['client'] : null;
        $facts = $scope !== self::SCOPE_SEEDS && is_array($data['facts'] ?? null) ? $data['facts'] : [];
        $seeds = $scope !== self::SCOPE_FACTS && is_array($data['seeds'] ?? null) ? $data['seeds'] : [];
        $result = $this->emptyResult($client, $scope, $update, $dryRun);

        if ($facts === [] && $scope !== self::SCOPE_SEEDS) {
            $this->addMessage($result, 'facts', 'warning', 'Aucun fait trouve dans la cle "facts".');
        }

        if ($seeds === [] && $scope !== self::SCOPE_FACTS) {
            $this->addMessage($result, 'seeds', 'warning', 'Aucun seed trouve dans la cle "seeds".');
        }

        $factRepository = $this->entityManager->getRepository(SeoFact::class);
        $seedRepository = $this->entityManager->getRepository(SeoSeed::class);

        foreach ($facts as $index => $row) {
            $label = sprintf('fact #%d', $index + 1);

            if (!is_array($row)) {
                ++$result['stats']['facts']['errors'];
                $this->addMessage($result, 'facts', 'error', sprintf('%s: entree invalide, objet attendu.', $label));

                continue;
            }

            $error = $this->validateFact($row);

            if ($error !== null) {
                ++$result['stats']['facts']['errors'];
                $this->addMessage($result, 'facts', 'error', sprintf('%s (%s): %s', $label, (string) ($row['name'] ?? '?'), $error));

                continue;
            }

            $name = trim((string) $row['name']);
            $type = (string) $row['type'];
            $locale = strtolower(trim((string) ($row['locale'] ?? 'fr'))) ?: 'fr';

            $existing = $factRepository->findOneBy([
                'name' => $name,
                'type' => $type,
                'locale' => $locale,
            ]);

            if ($existing instanceof SeoFact && !$update) {
                ++$result['stats']['facts']['skipped'];
                $this->addMessage($result, 'facts', 'skipped', sprintf('%s [%s] existe deja, id=%d.', $name, $type, $existing->getId()));

                continue;
            }

            $fact = $existing instanceof SeoFact ? $existing : new SeoFact();
            $fact
                ->setName($name)
                ->setType($type)
                ->setLocale($locale)
                ->setContent(trim((string) $row['content']))
                ->setPriority($this->intInRange($row['priority'] ?? 0))
                ->setValid((bool) ($row['valid'] ?? true));

            if (!$dryRun) {
                $this->entityManager->persist($fact);
            }

            if ($existing instanceof SeoFact) {
                ++$result['stats']['facts']['updated'];
                $this->addMessage($result, 'facts', 'updated', sprintf('%s [%s] mis a jour, id=%d.', $name, $type, $existing->getId()));
            } else {
                ++$result['stats']['facts']['created'];
                $this->addMessage($result, 'facts', 'created', sprintf('%s [%s] cree, priorite=%d.', $name, $type, $fact->getPriority()));
            }
        }

        foreach ($seeds as $index => $row) {
            $this->importSeedRow($row, $index, $update, $dryRun, $result, $seedRepository);
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return $result;
    }

    private function importSeedRow(mixed $row, int $index, bool $update, bool $dryRun, array &$result, ObjectRepository $seedRepository): void
    {
        $label = sprintf('seed #%d', $index + 1);

        if (!is_array($row)) {
            ++$result['stats']['seeds']['errors'];
            $this->addMessage($result, 'seeds', 'error', sprintf('%s: entree invalide, objet attendu.', $label));

            return;
        }

        $error = $this->validateSeed($row);

        if ($error !== null) {
            ++$result['stats']['seeds']['errors'];
            $this->addMessage($result, 'seeds', 'error', sprintf('%s (%s): %s', $label, (string) ($row['mainKeyword'] ?? '?'), $error));

            return;
        }

        $mainKeyword = SeoSeed::cleanPlainTextLine((string) $row['mainKeyword']);
        $city = SeoSeed::cleanPlainTextLine((string) ($row['city'] ?? '')) ?: null;
        $locale = strtolower(trim((string) ($row['locale'] ?? 'fr'))) ?: 'fr';
        $existing = $seedRepository->findOneBy([
            'mainKeyword' => $mainKeyword,
            'city' => $city,
            'locale' => $locale,
        ]);

        if ($existing instanceof SeoSeed && !$update) {
            ++$result['stats']['seeds']['skipped'];
            $this->addMessage($result, 'seeds', 'skipped', sprintf('%s existe deja, id=%d.', $mainKeyword, $existing->getId()));

            return;
        }

        $modelPreference = (string) ($row['claudeModelPreference'] ?? SeoSeed::CLAUDE_MODEL_AUTO);

        if (!in_array($modelPreference, SeoSeed::CLAUDE_MODEL_PREFERENCES, true)) {
            $this->addMessage($result, 'seeds', 'warning', sprintf('%s: modele "%s" inconnu, remplace par "%s".', $label, $modelPreference, SeoSeed::CLAUDE_MODEL_AUTO));
            $modelPreference = SeoSeed::CLAUDE_MODEL_AUTO;
        }

        $seed = $existing instanceof SeoSeed ? $existing : new SeoSeed();
        $seed
            ->setMainKeyword($mainKeyword)
            ->setService((string) $row['service'])
            ->setCity($city)
            ->setDepartment(isset($row['department']) ? (string) $row['department'] : null)
            ->setLocale($locale)
            ->setServicePageUrl(isset($row['servicePageUrl']) ? (string) $row['servicePageUrl'] : null)
            ->setServicePageLabel(isset($row['servicePageLabel']) ? (string) $row['servicePageLabel'] : null)
            ->setSecondaryKeywords($this->stringList($row['secondaryKeywords'] ?? []))
            ->setPageKeywords($this->stringList($row['pageKeywords'] ?? []))
            ->setIntent(isset($row['intent']) ? (string) $row['intent'] : null)
            ->setNotes(isset($row['notes']) ? (string) $row['notes'] : null)
            ->setBusinessValue($this->intInRange($row['businessValue'] ?? 50))
            ->setPriority($this->intInRange($row['priority'] ?? 0))
            ->setClaudeModelPreference($modelPreference)
            ->setValid((bool) ($row['valid'] ?? true));

        if (!$dryRun) {
            $this->entityManager->persist($seed);
        }

        if ($existing instanceof SeoSeed) {
            ++$result['stats']['seeds']['updated'];
            $this->addMessage($result, 'seeds', 'updated', sprintf('%s mis a jour, id=%d.', $mainKeyword, $existing->getId()));
        } else {
            ++$result['stats']['seeds']['created'];
            $this->addMessage($result, 'seeds', 'created', sprintf('%s cree, ville: %s, pages enfants: %d.', $mainKeyword, $city ?? 'sans ville', count($this->stringList($row['pageKeywords'] ?? []))));
        }
    }

    private function emptyResult(?string $client, string $scope, bool $update, bool $dryRun): array
    {
        return [
            'client' => $client,
            'scope' => $scope,
            'update' => $update,
            'dryRun' => $dryRun,
            'stats' => [
                'facts' => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0],
                'seeds' => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0],
            ],
            'messages' => [
                'facts' => [],
                'seeds' => [],
            ],
        ];
    }

    private function addMessage(array &$result, string $group, string $level, string $message): void
    {
        $result['messages'][$group][] = [
            'level' => $level,
            'message' => $message,
        ];
    }

    private function validateFact(array $row): ?string
    {
        $name = trim((string) ($row['name'] ?? ''));

        if ($name === '') {
            return 'champ "name" manquant ou vide';
        }

        if (mb_strlen($name) > 150) {
            return sprintf('champ "name" trop long (%d caracteres, max 150)', mb_strlen($name));
        }

        $type = (string) ($row['type'] ?? '');

        if (!in_array($type, self::VALID_FACT_TYPES, true)) {
            return sprintf('type "%s" invalide (attendus: %s)', $type, implode(', ', self::VALID_FACT_TYPES));
        }

        if (trim((string) ($row['content'] ?? '')) === '') {
            return 'champ "content" manquant ou vide';
        }

        return null;
    }

    private function validateSeed(array $row): ?string
    {
        $mainKeyword = SeoSeed::cleanPlainTextLine((string) ($row['mainKeyword'] ?? ''));

        if ($mainKeyword === '') {
            return 'champ "mainKeyword" manquant ou vide';
        }

        if (mb_strlen($mainKeyword) > 255) {
            return 'champ "mainKeyword" trop long (max 255)';
        }

        $service = SeoSeed::cleanPlainTextLine((string) ($row['service'] ?? ''));

        if ($service === '') {
            return 'champ "service" manquant ou vide';
        }

        if (mb_strlen($service) > 180) {
            return 'champ "service" trop long (max 180)';
        }

        foreach (['city' => 120, 'department' => 120, 'servicePageUrl' => 255, 'servicePageLabel' => 150] as $field => $max) {
            $value = SeoSeed::cleanPlainTextLine((string) ($row[$field] ?? ''));

            if ($value !== '' && mb_strlen($value) > $max) {
                return sprintf('champ "%s" trop long (max %d)', $field, $max);
            }
        }

        return null;
    }

    private function intInRange(mixed $value): int
    {
        return max(0, min(100, (int) $value));
    }

    /**
     * @return string[]
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            return SeoSeed::splitPlainTextLines($value);
        }

        if (!is_array($value)) {
            return [];
        }

        return SeoSeed::cleanPlainTextLines(array_map(static fn (mixed $item): string => (string) $item, $value));
    }
}
