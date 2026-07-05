<?php

namespace App\Entity;

use App\Entity\Trait\CreatedAtTrait;
use App\Entity\Trait\UpdatedAtTrait;
use App\Repository\SeoSeedRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SeoSeedRepository::class)]
#[ORM\HasLifecycleCallbacks]
class SeoSeed
{
    use CreatedAtTrait, UpdatedAtTrait;

    public const CLAUDE_MODEL_AUTO = 'auto';
    public const CLAUDE_MODEL_SONNET = 'sonnet';
    public const CLAUDE_MODEL_SONNET_5 = 'sonnet_5';
    public const CLAUDE_MODEL_OPUS = 'opus';
    public const CLAUDE_MODEL_FABLE = 'fable';

    public const CLAUDE_MODEL_PREFERENCES = [
        self::CLAUDE_MODEL_AUTO,
        self::CLAUDE_MODEL_SONNET,
        self::CLAUDE_MODEL_SONNET_5,
        self::CLAUDE_MODEL_OPUS,
        self::CLAUDE_MODEL_FABLE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $service = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $department = null;

    #[ORM\Column(length: 255)]
    private ?string $mainKeyword = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $servicePageUrl = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $servicePageLabel = null;

    #[ORM\Column]
    private array $secondaryKeywords = [];

    #[ORM\Column(nullable: true)]
    private ?array $pageKeywords = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $intent = null;

    #[ORM\Column]
    private int $businessValue = 50;

    #[ORM\Column]
    private int $dataCompletenessScore = 0;

    #[ORM\Column(length: 5)]
    private string $locale = 'fr';

    #[ORM\Column]
    private int $priority = 0;

    #[ORM\Column]
    private bool $valid = true;

    #[ORM\Column(length: 20, options: ['default' => 'auto'])]
    private string $claudeModelPreference = self::CLAUDE_MODEL_AUTO;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function __toString(): string
    {
        return (string) ($this->getMainKeyword() ?: $this->getService());
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->setCreatedAt(new \DateTimeImmutable());
        $this->refreshDataCompletenessScore();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->setUpdatedAt(new \DateTimeImmutable());
        $this->refreshDataCompletenessScore();
    }

    public function refreshDataCompletenessScore(): void
    {
        $this->dataCompletenessScore = $this->calculateDataCompletenessScore();
    }

    private function calculateDataCompletenessScore(): int
    {
        $score = 0;
        $score += $this->service ? 20 : 0;
        $score += $this->mainKeyword ? 20 : 0;
        $score += $this->city ? 15 : 0;
        $score += $this->department ? 10 : 0;
        $score += $this->intent ? 15 : 0;
        $score += count($this->secondaryKeywords) > 0 ? 10 : 0;
        $score += $this->notes ? 10 : 0;

        return min(100, $score);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getService(): ?string
    {
        return self::cleanPlainTextLine($this->service) ?: null;
    }

    public function setService(string $service): self
    {
        $this->service = self::cleanPlainTextLine($service);

        return $this;
    }

    public function getCity(): ?string
    {
        return self::cleanPlainTextLine($this->city) ?: null;
    }

    public function setCity(?string $city): self
    {
        $this->city = self::cleanPlainTextLine($city) ?: null;

        return $this;
    }

    public function getDepartment(): ?string
    {
        return self::cleanPlainTextLine($this->department) ?: null;
    }

    public function setDepartment(?string $department): self
    {
        $this->department = self::cleanPlainTextLine($department) ?: null;

        return $this;
    }

    public function getMainKeyword(): ?string
    {
        return self::cleanPlainTextLine($this->mainKeyword) ?: null;
    }

    public function setMainKeyword(string $mainKeyword): self
    {
        $this->mainKeyword = self::cleanPlainTextLine($mainKeyword);

        return $this;
    }

    public function getServicePageUrl(): ?string
    {
        return self::cleanPlainTextLine($this->servicePageUrl) ?: null;
    }

    public function setServicePageUrl(?string $servicePageUrl): self
    {
        $this->servicePageUrl = $servicePageUrl ? self::cleanPlainTextLine($servicePageUrl) : null;

        return $this;
    }

    public function getServicePageLabel(): ?string
    {
        return self::cleanPlainTextLine($this->servicePageLabel) ?: null;
    }

    public function setServicePageLabel(?string $servicePageLabel): self
    {
        $this->servicePageLabel = $servicePageLabel ? self::cleanPlainTextLine($servicePageLabel) : null;

        return $this;
    }

    public function getSecondaryKeywords(): array
    {
        return self::cleanPlainTextLines($this->secondaryKeywords);
    }

    public function setSecondaryKeywords(array $secondaryKeywords): self
    {
        $this->secondaryKeywords = self::cleanPlainTextLines($secondaryKeywords);

        return $this;
    }

    public function getSecondaryKeywordsText(): string
    {
        return implode("\n", $this->getSecondaryKeywords());
    }

    public function setSecondaryKeywordsText(?string $secondaryKeywordsText): self
    {
        $this->secondaryKeywords = self::splitPlainTextLines($secondaryKeywordsText);

        return $this;
    }

    public function getPageKeywords(): array
    {
        return self::cleanPlainTextLines($this->pageKeywords ?: []);
    }

    public function setPageKeywords(?array $pageKeywords): self
    {
        $this->pageKeywords = self::cleanPlainTextLines($pageKeywords ?: []);

        return $this;
    }

    public function getPageKeywordsText(): string
    {
        return implode("\n", $this->getPageKeywords());
    }

    public function setPageKeywordsText(?string $pageKeywordsText): self
    {
        $this->pageKeywords = self::splitPlainTextLines($pageKeywordsText);

        return $this;
    }

    public function getIntent(): ?string
    {
        return self::cleanPlainTextBlock($this->intent);
    }

    public function setIntent(?string $intent): self
    {
        $this->intent = self::cleanPlainTextBlock($intent);

        return $this;
    }

    public function getBusinessValue(): int
    {
        return $this->businessValue;
    }

    public function setBusinessValue(int $businessValue): self
    {
        $this->businessValue = max(0, min(100, $businessValue));

        return $this;
    }

    public function getDataCompletenessScore(): int
    {
        return $this->calculateDataCompletenessScore();
    }

    public function setDataCompletenessScore(int $dataCompletenessScore): self
    {
        $this->dataCompletenessScore = max(0, min(100, $dataCompletenessScore));

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = strtolower(self::cleanPlainTextLine($locale) ?: 'fr');

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function setValid(bool $valid): self
    {
        $this->valid = $valid;

        return $this;
    }

    public function getClaudeModelPreference(): string
    {
        return $this->claudeModelPreference ?: self::CLAUDE_MODEL_AUTO;
    }

    public function setClaudeModelPreference(?string $claudeModelPreference): self
    {
        $claudeModelPreference = $claudeModelPreference ?: self::CLAUDE_MODEL_AUTO;

        if (!in_array($claudeModelPreference, self::CLAUDE_MODEL_PREFERENCES, true)) {
            $claudeModelPreference = self::CLAUDE_MODEL_AUTO;
        }

        $this->claudeModelPreference = $claudeModelPreference;

        return $this;
    }

    public function getNotes(): ?string
    {
        return self::cleanPlainTextBlock($this->notes);
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = self::cleanPlainTextBlock($notes);

        return $this;
    }

    /**
     * @param array<int, mixed> $values
     *
     * @return string[]
     */
    public static function cleanPlainTextLines(array $values): array
    {
        $lines = [];

        foreach ($values as $value) {
            foreach (self::splitPlainTextLines((string) $value) as $line) {
                $lines[] = $line;
            }
        }

        return array_values(array_unique($lines));
    }

    /**
     * @return string[]
     */
    public static function splitPlainTextLines(?string $value): array
    {
        $text = self::htmlToPlainText($value);

        if ($text === '') {
            return [];
        }

        $lines = preg_split('/\n+/', $text) ?: [];
        $lines = array_map(static fn (string $line): string => self::cleanPlainTextLine($line), $lines);

        return array_values(array_unique(array_filter($lines, static fn (string $line): bool => $line !== '')));
    }

    public static function cleanPlainTextLine(?string $value): string
    {
        $text = self::htmlToPlainText($value);

        return trim(preg_replace('/\s+/', ' ', $text) ?: '');
    }

    public static function cleanPlainTextBlock(?string $value): ?string
    {
        $text = self::htmlToPlainText($value);

        return $text !== '' ? $text : null;
    }

    private static function htmlToPlainText(?string $value): string
    {
        $text = (string) $value;

        if ($text === '') {
            return '';
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $text) ?: $text;
        $text = preg_replace('/<\/\s*(p|div|li|pre|code|h[1-6])\s*>/i', "\n", $text) ?: $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text);
        $text = preg_replace("/\r\n|\r/", "\n", $text) ?: $text;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?: $text;
        $text = preg_replace("/ *\n+ */", "\n", $text) ?: $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?: $text;

        return trim($text);
    }
}
