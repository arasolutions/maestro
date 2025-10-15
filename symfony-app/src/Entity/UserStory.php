<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'user_stories', schema: 'maestro')]
class UserStory
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'uuid', name: 'analysis_id', nullable: true)]
    private ?Uuid $analysisId = null;

    #[ORM\Column(type: 'uuid', name: 'cadrage_id', nullable: true)]
    private ?Uuid $cadrageId = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $stories = null;

    #[ORM\Column(type: Types::JSON, name: 'acceptance_criteria', nullable: true)]
    private ?array $acceptanceCriteria = null;

    #[ORM\Column(type: Types::INTEGER, name: 'story_points', nullable: true)]
    private ?int $storyPoints = null;

    #[ORM\Column(type: Types::JSON, name: 'priority_order', nullable: true)]
    private ?array $priorityOrder = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $dependencies = null;

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

    public function getAnalysisId(): ?Uuid
    {
        return $this->analysisId;
    }

    public function setAnalysisId(?Uuid $analysisId): static
    {
        $this->analysisId = $analysisId;
        return $this;
    }

    public function getCadrageId(): ?Uuid
    {
        return $this->cadrageId;
    }

    public function setCadrageId(?Uuid $cadrageId): static
    {
        $this->cadrageId = $cadrageId;
        return $this;
    }

    public function getStories(): ?array
    {
        return $this->stories;
    }

    public function setStories(?array $stories): static
    {
        $this->stories = $stories;
        return $this;
    }

    public function getAcceptanceCriteria(): ?array
    {
        return $this->acceptanceCriteria;
    }

    public function setAcceptanceCriteria(?array $acceptanceCriteria): static
    {
        $this->acceptanceCriteria = $acceptanceCriteria;
        return $this;
    }

    public function getStoryPoints(): ?int
    {
        return $this->storyPoints;
    }

    public function setStoryPoints(?int $storyPoints): static
    {
        $this->storyPoints = $storyPoints;
        return $this;
    }

    public function getPriorityOrder(): ?array
    {
        return $this->priorityOrder;
    }

    public function setPriorityOrder(?array $priorityOrder): static
    {
        $this->priorityOrder = $priorityOrder;
        return $this;
    }

    public function getDependencies(): ?array
    {
        return $this->dependencies;
    }

    public function setDependencies(?array $dependencies): static
    {
        $this->dependencies = $dependencies;
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
}
