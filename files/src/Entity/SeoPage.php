<?php

namespace App\Entity;

use App\Entity\Trait\CreatedAtTrait;
use App\Entity\Trait\UpdatedAtTrait;
use App\Repository\SeoPageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SeoPageRepository::class)]
#[ORM\Table(name: 'seo_page')]
#[ORM\UniqueConstraint(name: 'uniq_seo_page_slug_locale', columns: ['slug', 'locale'])]
#[ORM\HasLifecycleCallbacks]
class SeoPage
{
    use CreatedAtTrait, UpdatedAtTrait;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_REVIEW = 'review';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?SeoSeed $seed = null;

    #[ORM\Column(length: 180)]
    private ?string $slug = null;

    #[ORM\Column(length: 5)]
    private string $locale = 'fr';

    #[ORM\Column(length: 70)]
    private ?string $title = null;

    #[ORM\Column(length: 180)]
    private ?string $metaDescription = null;

    #[ORM\Column(length: 180)]
    private ?string $h1 = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $intro = null;

    #[ORM\Column]
    private array $content = [];

    #[ORM\Column]
    private array $faq = [];

    #[ORM\Column]
    private array $schemaJson = [];

    #[ORM\Column]
    private array $internalLinks = [];

    #[ORM\Column]
    private array $templateCopy = [];

    #[ORM\Column]
    private array $imageAltSuggestions = [];

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $cta = null;

    #[ORM\Column]
    private array $qualityFlags = [];

    #[ORM\Column]
    private array $missingData = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rawClaudeResponse = null;

    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column]
    private bool $indexable = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $canonicalUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $mainKeyword = null;

    #[ORM\Column]
    private int $qualityScore = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $generatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    public function __toString(): string
    {
        return (string) ($this->getTitle() ?: $this->slug);
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->setCreatedAt(new \DateTimeImmutable());
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->setUpdatedAt(new \DateTimeImmutable());
    }

    public function isPublishedIndexable(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->indexable;
    }

    public function getPublicPath(): string
    {
        return '/' . $this->slug;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSeed(): ?SeoSeed
    {
        return $this->seed;
    }

    public function setSeed(?SeoSeed $seed): self
    {
        $this->seed = $seed;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->decodeText($this->title);
    }

    public function setTitle(string $title): self
    {
        $this->title = $this->limit($this->decodeText($title) ?: '', 70);

        return $this;
    }

    public function getMetaDescription(): ?string
    {
        return $this->decodeText($this->metaDescription);
    }

    public function setMetaDescription(string $metaDescription): self
    {
        $this->metaDescription = $this->limit($this->decodeText($metaDescription) ?: '', 180);

        return $this;
    }

    public function getH1(): ?string
    {
        return $this->decodeText($this->h1);
    }

    public function setH1(string $h1): self
    {
        $this->h1 = $this->limit($this->decodeText($h1) ?: '', 180);

        return $this;
    }

    public function getIntro(): ?string
    {
        return $this->decodeText($this->intro);
    }

    public function setIntro(?string $intro): self
    {
        $this->intro = $this->decodeText($intro);

        return $this;
    }

    public function getContent(): array
    {
        return $this->decodeStructuredText($this->content);
    }

    public function setContent(array $content): self
    {
        $this->content = $this->decodeStructuredText($content);

        return $this;
    }

    public function getContentJson(): string
    {
        return json_encode($this->getContent(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '[]';
    }

    public function setContentJson(?string $contentJson): self
    {
        $decoded = $this->decodeJsonArrayField($contentJson);

        if ($decoded === null || ($decoded === [] && $this->content !== [])) {
            return $this;
        }

        $this->content = $this->decodeStructuredText($decoded);

        return $this;
    }

    public function getFaq(): array
    {
        return $this->decodeStructuredText($this->faq);
    }

    public function setFaq(array $faq): self
    {
        $this->faq = $this->decodeStructuredText($faq);

        return $this;
    }

    public function getFaqJson(): string
    {
        return json_encode($this->getFaq(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '[]';
    }

    public function setFaqJson(?string $faqJson): self
    {
        $decoded = $this->decodeJsonArrayField($faqJson);

        if ($decoded === null || ($decoded === [] && $this->faq !== [])) {
            return $this;
        }

        $this->faq = $this->decodeStructuredText($decoded);

        return $this;
    }

    public function getSchemaJson(): array
    {
        return $this->schemaJson;
    }

    public function setSchemaJson(array $schemaJson): self
    {
        $this->schemaJson = $schemaJson;

        return $this;
    }

    public function getSchemaJsonText(): string
    {
        return json_encode($this->schemaJson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
    }

    public function setSchemaJsonText(?string $schemaJsonText): self
    {
        $decoded = $this->decodeJsonArrayField($schemaJsonText);

        if ($decoded === null || ($decoded === [] && $this->schemaJson !== [])) {
            return $this;
        }

        $this->schemaJson = $decoded;

        return $this;
    }

    public function getInternalLinks(): array
    {
        return $this->decodeStructuredText($this->internalLinks);
    }

    public function setInternalLinks(array $internalLinks): self
    {
        $this->internalLinks = $this->decodeStructuredText($internalLinks);

        return $this;
    }

    public function getInternalLinksJson(): string
    {
        return json_encode($this->getInternalLinks(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '[]';
    }

    public function setInternalLinksJson(?string $internalLinksJson): self
    {
        $decoded = $this->decodeJsonArrayField($internalLinksJson);

        if ($decoded === null || ($decoded === [] && $this->internalLinks !== [])) {
            return $this;
        }

        $this->internalLinks = $this->decodeStructuredText($decoded);

        return $this;
    }

    public function getTemplateCopy(): array
    {
        return $this->decodeStructuredText($this->templateCopy);
    }

    public function setTemplateCopy(array $templateCopy): self
    {
        $this->templateCopy = $this->decodeStructuredText($templateCopy);

        return $this;
    }

    public function getTemplateCopyJson(): string
    {
        return json_encode($this->getTemplateCopy(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
    }

    public function setTemplateCopyJson(?string $templateCopyJson): self
    {
        $decoded = $this->decodeJsonArrayField($templateCopyJson);

        if ($decoded === null || ($decoded === [] && $this->templateCopy !== [])) {
            return $this;
        }

        $this->templateCopy = $this->decodeStructuredText($decoded);

        return $this;
    }

    public function getCta(): ?string
    {
        return $this->normalizeCtaLabel($this->cta);
    }

    public function setCta(?string $cta): self
    {
        $this->cta = $this->normalizeCtaLabel($cta);

        return $this;
    }

    public function getImageAltSuggestions(): array
    {
        return $this->cleanTextLines($this->imageAltSuggestions);
    }

    public function setImageAltSuggestions(array $imageAltSuggestions): self
    {
        $this->imageAltSuggestions = $this->cleanTextLines($imageAltSuggestions);

        return $this;
    }

    public function getImageAltSuggestionsText(): string
    {
        return implode("\n", $this->getImageAltSuggestions());
    }

    public function getQualityFlags(): array
    {
        return $this->cleanTextLines($this->qualityFlags);
    }

    public function setQualityFlags(array $qualityFlags): self
    {
        $this->qualityFlags = $this->cleanTextLines($qualityFlags);

        return $this;
    }

    public function getQualityFlagsText(): string
    {
        return implode("\n", $this->getQualityFlags());
    }

    public function setQualityFlagsText(?string $qualityFlagsText): self
    {
        $this->qualityFlags = $this->splitTextLines($qualityFlagsText);

        return $this;
    }

    public function getMissingData(): array
    {
        return $this->cleanTextLines($this->missingData);
    }

    public function setMissingData(array $missingData): self
    {
        $this->missingData = $this->cleanTextLines($missingData);

        return $this;
    }

    public function getMissingDataText(): string
    {
        return implode("\n", $this->getMissingData());
    }

    public function setMissingDataText(?string $missingDataText): self
    {
        $this->missingData = $this->splitTextLines($missingDataText);

        return $this;
    }

    public function getRawClaudeResponse(): ?string
    {
        return $this->rawClaudeResponse;
    }

    public function setRawClaudeResponse(?string $rawClaudeResponse): self
    {
        $this->rawClaudeResponse = $rawClaudeResponse;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function isIndexable(): bool
    {
        return $this->indexable;
    }

    public function setIndexable(bool $indexable): self
    {
        $this->indexable = $indexable;

        return $this;
    }

    public function getCanonicalUrl(): ?string
    {
        return $this->canonicalUrl;
    }

    public function setCanonicalUrl(?string $canonicalUrl): self
    {
        $canonicalUrl = trim((string) $canonicalUrl);
        $this->canonicalUrl = $canonicalUrl !== '' ? $canonicalUrl : null;

        return $this;
    }

    public function getMainKeyword(): ?string
    {
        return $this->decodeText($this->mainKeyword);
    }

    public function getCleanMainKeyword(): ?string
    {
        return $this->cleanTextLabel($this->mainKeyword);
    }

    public function getCleanH1(): ?string
    {
        return $this->cleanTextLabel($this->h1);
    }

    public function setMainKeyword(?string $mainKeyword): self
    {
        $this->mainKeyword = $this->decodeText($mainKeyword);

        return $this;
    }

    public function getQualityScore(): int
    {
        return $this->qualityScore;
    }

    public function setQualityScore(int $qualityScore): self
    {
        $this->qualityScore = max(0, min(100, $qualityScore));

        return $this;
    }

    public function getGeneratedAt(): ?\DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function setGeneratedAt(?\DateTimeImmutable $generatedAt): self
    {
        $this->generatedAt = $generatedAt;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): self
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    private function limit(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }

        return substr($value, 0, $length);
    }

    private function decodeStructuredText(array $value): array
    {
        $decoded = [];

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $decoded[$key] = $this->decodeStructuredText($item);
                continue;
            }

            if (is_string($item)) {
                $decoded[$key] = $this->decodeText($item) ?: '';
                continue;
            }

            $decoded[$key] = $item;
        }

        return $decoded;
    }

    private function decodeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $decoded = $value;

        for ($i = 0; $i < 3; $i++) {
            $next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($next === $decoded) {
                break;
            }

            $decoded = $next;
        }

        return trim($decoded);
    }

    private function decodeJsonArrayField(?string $value): ?array
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function cleanTextLabel(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $value = strip_tags($this->decodeText($value) ?: '');
        $value = trim(preg_replace('/\s+/', ' ', $value) ?: '');

        return $value !== '' ? $value : null;
    }

    private function normalizeCtaLabel(?string $cta): ?string
    {
        $cta = $this->cleanTextLabel($cta);

        if (!$cta) {
            return null;
        }

        $normalized = $this->normalizeForSearch($cta);

        if (str_contains($normalized, 'eligibil')) {
            return 'Tester mon éligibilité';
        }

        if (str_contains($normalized, 'rappel') || str_contains($normalized, 'conseiller')) {
            return 'Être rappelé';
        }

        if ($this->textLength($cta) <= 38) {
            return $cta;
        }

        if (str_contains($normalized, 'devis')) {
            return 'Demander un devis';
        }

        if (str_contains($normalized, 'appel') || str_contains($normalized, 'telephone')) {
            return 'Appeler';
        }

        if (str_contains($normalized, 'contact') || str_contains($normalized, 'formulaire') || str_contains($normalized, 'demande')) {
            return 'Faire une demande';
        }

        return 'Faire une demande';
    }

    private function normalizeForSearch(string $value): string
    {
        $value = strtolower($value);

        if (!function_exists('iconv')) {
            return $value;
        }

        $asciiValue = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return is_string($asciiValue) && $asciiValue !== '' ? $asciiValue : $value;
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function splitTextLines(?string $value): array
    {
        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);

        return $this->cleanTextLines(explode("\n", $value));
    }

    private function cleanTextLines(array $values): array
    {
        $cleanValues = [];

        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $value = strip_tags($this->decodeText((string) $value) ?: '');
            $value = trim(preg_replace('/\s+/', ' ', $value) ?: '');

            if ($value !== '') {
                $cleanValues[] = $value;
            }
        }

        return array_values(array_unique($cleanValues));
    }
}
