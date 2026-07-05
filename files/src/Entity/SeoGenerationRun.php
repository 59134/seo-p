<?php

namespace App\Entity;

use App\Repository\SeoGenerationRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SeoGenerationRunRepository::class)]
class SeoGenerationRun
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?SeoSeed $seed = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?SeoPage $page = null;

    #[ORM\Column(length: 50)]
    private string $provider = 'anthropic';

    #[ORM\Column(length: 100)]
    private string $model = 'not_configured';

    #[ORM\Column(length: 64)]
    private ?string $promptHash = null;

    #[ORM\Column]
    private array $requestPayload = [];

    #[ORM\Column(nullable: true)]
    private ?array $responsePayload = null;

    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(nullable: true)]
    private ?int $inputTokens = null;

    #[ORM\Column(nullable: true)]
    private ?int $outputTokens = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%s - %s', $this->provider, $this->status);
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

    public function getPage(): ?SeoPage
    {
        return $this->page;
    }

    public function setPage(?SeoPage $page): self
    {
        $this->page = $page;

        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function getPromptHash(): ?string
    {
        return $this->promptHash;
    }

    public function setPromptHash(string $promptHash): self
    {
        $this->promptHash = $promptHash;

        return $this;
    }

    public function getRequestPayload(): array
    {
        return $this->requestPayload;
    }

    public function setRequestPayload(array $requestPayload): self
    {
        $this->requestPayload = $requestPayload;

        return $this;
    }

    public function getResponsePayload(): ?array
    {
        return $this->responsePayload;
    }

    public function setResponsePayload(?array $responsePayload): self
    {
        $this->responsePayload = $responsePayload;

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

    public function getInputTokens(): ?int
    {
        return $this->inputTokens;
    }

    public function setInputTokens(?int $inputTokens): self
    {
        $this->inputTokens = $inputTokens;

        return $this;
    }

    public function getOutputTokens(): ?int
    {
        return $this->outputTokens;
    }

    public function setOutputTokens(?int $outputTokens): self
    {
        $this->outputTokens = $outputTokens;

        return $this;
    }

    public function getMaxTokens(): ?int
    {
        $maxTokens = $this->requestPayload['max_tokens'] ?? null;

        return is_numeric($maxTokens) ? (int) $maxTokens : null;
    }

    public function getStopReason(): ?string
    {
        $stopReason = $this->responsePayload['stop_reason'] ?? null;

        return is_string($stopReason) && trim($stopReason) !== '' ? trim($stopReason) : null;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
