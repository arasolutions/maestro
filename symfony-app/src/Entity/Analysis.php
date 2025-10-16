<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'analyses', schema: 'maestro')]
class Analysis
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private ?Uuid $id = null;

    #[ORM\Column(type: Types::TEXT, name: 'request_text')]
    private ?string $requestText = null;

    #[ORM\Column(length: 50, name: 'analysis_type')]
    private ?string $analysisType = null;

    #[ORM\Column(length: 10)]
    private ?string $complexity = null;

    #[ORM\Column(length: 20)]
    private ?string $priority = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 3, scale: 2)]
    private ?string $confidence = null;

    #[ORM\Column(type: Types::JSON, name: 'agents_needed')]
    private array $agentsNeeded = [];

    #[ORM\Column(type: Types::INTEGER, name: 'estimated_hours', nullable: true)]
    private ?int $estimatedHours = null;

    #[ORM\Column(type: Types::JSON, name: 'next_steps', nullable: true)]
    private ?array $nextSteps = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $risks = null;

    #[ORM\Column(type: Types::JSON, name: 'full_response', nullable: true)]
    private ?array $fullResponse = null;

    #[ORM\Column(length: 255, name: 'webhook_execution_id', nullable: true)]
    private ?string $webhookExecutionId = null;

    #[ORM\Column(type: 'uuid', name: 'project_id', nullable: true)]
    private ?Uuid $projectId = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\Column(type: 'uuid', name: 'request_id', nullable: true)]
    private ?Uuid $requestId = null;

    #[ORM\OneToOne(targetEntity: Request::class, inversedBy: 'analysis')]
    #[ORM\JoinColumn(name: 'request_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?Request $request = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, name: 'created_at')]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getRequestText(): ?string
    {
        return $this->requestText;
    }

    public function setRequestText(string $requestText): static
    {
        $this->requestText = $requestText;
        return $this;
    }

    public function getAnalysisType(): ?string
    {
        return $this->analysisType;
    }

    public function setAnalysisType(string $analysisType): static
    {
        $this->analysisType = $analysisType;
        return $this;
    }

    public function getComplexity(): ?string
    {
        return $this->complexity;
    }

    public function setComplexity(string $complexity): static
    {
        $this->complexity = $complexity;
        return $this;
    }

    public function getPriority(): ?string
    {
        return $this->priority;
    }

    public function setPriority(string $priority): static
    {
        $this->priority = $priority;
        return $this;
    }

    public function getConfidence(): ?float
    {
        return $this->confidence !== null ? (float) $this->confidence : null;
    }

    public function setConfidence(float $confidence): static
    {
        $this->confidence = (string) $confidence;
        return $this;
    }

    public function getAgentsNeeded(): array
    {
        return $this->agentsNeeded;
    }

    public function setAgentsNeeded(array $agentsNeeded): static
    {
        $this->agentsNeeded = $agentsNeeded;
        return $this;
    }

    public function getEstimatedHours(): ?int
    {
        return $this->estimatedHours;
    }

    public function setEstimatedHours(?int $estimatedHours): static
    {
        $this->estimatedHours = $estimatedHours;
        return $this;
    }

    public function getNextSteps(): ?array
    {
        return $this->nextSteps;
    }

    public function setNextSteps(?array $nextSteps): static
    {
        $this->nextSteps = $nextSteps;
        return $this;
    }

    public function getRisks(): ?array
    {
        return $this->risks;
    }

    public function setRisks(?array $risks): static
    {
        $this->risks = $risks;
        return $this;
    }

    public function getFullResponse(): ?array
    {
        return $this->fullResponse;
    }

    public function setFullResponse(?array $fullResponse): static
    {
        $this->fullResponse = $fullResponse;
        return $this;
    }

    public function getWebhookExecutionId(): ?string
    {
        return $this->webhookExecutionId;
    }

    public function setWebhookExecutionId(?string $webhookExecutionId): static
    {
        $this->webhookExecutionId = $webhookExecutionId;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getProjectId(): ?Uuid
    {
        return $this->projectId;
    }

    public function setProjectId(?Uuid $projectId): static
    {
        $this->projectId = $projectId;
        return $this;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): static
    {
        $this->project = $project;
        if ($project !== null) {
            $this->projectId = $project->getId();
        }
        return $this;
    }

    public function getRequestId(): ?Uuid
    {
        return $this->requestId;
    }

    public function setRequestId(?Uuid $requestId): static
    {
        $this->requestId = $requestId;
        return $this;
    }

    public function getRequest(): ?Request
    {
        return $this->request;
    }

    public function setRequest(?Request $request): static
    {
        $this->request = $request;
        if ($request !== null) {
            $this->requestId = $request->getId();
        }
        return $this;
    }
}
