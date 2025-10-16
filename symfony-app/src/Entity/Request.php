<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'requests', schema: 'maestro')]
#[ORM\Index(columns: ['status'], name: 'idx_requests_status')]
#[ORM\Index(columns: ['created_at'], name: 'idx_requests_created_at')]
#[ORM\Index(columns: ['project_id'], name: 'idx_requests_project_id')]
class Request
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: Types::TEXT)]
    private string $requestText;

    #[ORM\Column(length: 20, options: ['default' => 'PENDING'])]
    private string $status = 'PENDING';

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $projectId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $webhookExecutionId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $updatedAt = null;

    #[ORM\OneToOne(targetEntity: Analysis::class, mappedBy: 'request')]
    private ?Analysis $analysis = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->status = 'PENDING';
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRequestText(): string
    {
        return $this->requestText;
    }

    public function setRequestText(string $requestText): self
    {
        $this->requestText = $requestText;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getProjectId(): ?Uuid
    {
        return $this->projectId;
    }

    public function setProjectId(?Uuid $projectId): self
    {
        $this->projectId = $projectId;
        return $this;
    }

    public function getWebhookExecutionId(): ?string
    {
        return $this->webhookExecutionId;
    }

    public function setWebhookExecutionId(?string $webhookExecutionId): self
    {
        $this->webhookExecutionId = $webhookExecutionId;
        return $this;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updatedAt;
    }

    public function getAnalysis(): ?Analysis
    {
        return $this->analysis;
    }

    public function setAnalysis(?Analysis $analysis): self
    {
        $this->analysis = $analysis;
        return $this;
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'PROCESSING';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'COMPLETED';
    }

    public function isFailed(): bool
    {
        return $this->status === 'FAILED';
    }
}
