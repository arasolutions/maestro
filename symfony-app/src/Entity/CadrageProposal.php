<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'cadrage_proposals', schema: 'maestro')]
class CadrageProposal
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'uuid', name: 'analysis_id', nullable: true)]
    private ?Uuid $analysisId = null;

    #[ORM\Column(type: 'uuid', name: 'project_id', nullable: true)]
    private ?Uuid $projectId = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $status = 'PENDING';

    #[ORM\Column(type: Types::DATETIME_MUTABLE, name: 'applied_at', nullable: true)]
    private ?\DateTimeInterface $appliedAt = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $perimetre = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $contraintes = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $architecture = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $swot = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $estimation = null;

    #[ORM\Column(type: Types::JSON, name: 'full_response', nullable: true)]
    private ?array $fullResponse = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, name: 'created_at')]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTime();
        $this->status = 'PENDING';
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getAnalysisId(): ?Uuid
    {
        return $this->analysisId;
    }

    public function setAnalysisId(?Uuid $analysisId): static
    {
        $this->analysisId = $analysisId;
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

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getAppliedAt(): ?\DateTimeInterface
    {
        return $this->appliedAt;
    }

    public function setAppliedAt(?\DateTimeInterface $appliedAt): static
    {
        $this->appliedAt = $appliedAt;
        return $this;
    }

    public function getPerimetre(): ?array
    {
        return $this->perimetre;
    }

    public function setPerimetre(?array $perimetre): static
    {
        $this->perimetre = $perimetre;
        return $this;
    }

    public function getContraintes(): ?array
    {
        return $this->contraintes;
    }

    public function setContraintes(?array $contraintes): static
    {
        $this->contraintes = $contraintes;
        return $this;
    }

    public function getArchitecture(): ?array
    {
        return $this->architecture;
    }

    public function setArchitecture(?array $architecture): static
    {
        $this->architecture = $architecture;
        return $this;
    }

    public function getSwot(): ?array
    {
        return $this->swot;
    }

    public function setSwot(?array $swot): static
    {
        $this->swot = $swot;
        return $this;
    }

    public function getEstimation(): ?array
    {
        return $this->estimation;
    }

    public function setEstimation(?array $estimation): static
    {
        $this->estimation = $estimation;
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

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    /**
     * Accept this proposal and mark it as applied
     */
    public function accept(): static
    {
        $this->status = 'ACCEPTED';
        $this->appliedAt = new \DateTime();
        return $this;
    }

    /**
     * Reject this proposal
     */
    public function reject(): static
    {
        $this->status = 'REJECTED';
        return $this;
    }

    /**
     * Check if proposal is still pending
     */
    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    /**
     * Check if proposal was accepted
     */
    public function isAccepted(): bool
    {
        return $this->status === 'ACCEPTED';
    }

    /**
     * Convert proposal to array for merging into project cadrage
     */
    public function toArray(): array
    {
        return array_filter([
            'perimetre' => $this->perimetre,
            'contraintes' => $this->contraintes,
            'architecture' => $this->architecture,
            'swot' => $this->swot,
            'estimation' => $this->estimation,
        ], fn($v) => $v !== null);
    }
}
