<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'projects', schema: 'maestro')]
class Project
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private ?Uuid $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $config = null;

    #[ORM\Column(type: Types::JSON, name: 'project_cadrage', nullable: true)]
    private ?array $projectCadrage = null;

    #[ORM\Column(name: 'project_cadrage_version', nullable: true)]
    private ?int $projectCadrageVersion = 1;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, name: 'project_cadrage_updated_at', nullable: true)]
    private ?\DateTimeInterface $projectCadrageUpdatedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, name: 'created_at')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(name: 'gitea_repo_id', nullable: true)]
    private ?int $giteaRepoId = null;

    #[ORM\Column(length: 255, name: 'gitea_repo_name', nullable: true)]
    private ?string $giteaRepoName = null;

    #[ORM\Column(length: 500, name: 'gitea_repo_url', nullable: true)]
    private ?string $giteaRepoUrl = null;

    #[ORM\Column(length: 100, name: 'gitea_webhook_secret', nullable: true)]
    private ?string $giteaWebhookSecret = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTime();
        $this->projectCadrageVersion = 1;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getConfig(): ?array
    {
        return $this->config;
    }

    public function setConfig(?array $config): static
    {
        $this->config = $config;
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

    public function getProjectCadrage(): ?array
    {
        return $this->projectCadrage;
    }

    public function setProjectCadrage(?array $projectCadrage): static
    {
        $this->projectCadrage = $projectCadrage;
        $this->projectCadrageVersion = ($this->projectCadrageVersion ?? 0) + 1;
        $this->projectCadrageUpdatedAt = new \DateTime();
        return $this;
    }

    public function getProjectCadrageVersion(): ?int
    {
        return $this->projectCadrageVersion;
    }

    public function getProjectCadrageUpdatedAt(): ?\DateTimeInterface
    {
        return $this->projectCadrageUpdatedAt;
    }

    /**
     * Merge a cadrage proposal into the project cadrage
     */
    public function mergeCadrageProposal(array $proposal): static
    {
        $current = $this->projectCadrage ?? [];

        // Merge intelligently: new values override old ones
        $merged = array_merge($current, array_filter($proposal, fn($v) => $v !== null));

        $this->setProjectCadrage($merged);

        return $this;
    }

    /**
     * Check if project has a cadrage defined
     */
    public function hasCadrage(): bool
    {
        return $this->projectCadrage !== null && !empty($this->projectCadrage);
    }

    public function getGiteaRepoId(): ?int
    {
        return $this->giteaRepoId;
    }

    public function setGiteaRepoId(?int $giteaRepoId): static
    {
        $this->giteaRepoId = $giteaRepoId;
        return $this;
    }

    public function getGiteaRepoName(): ?string
    {
        return $this->giteaRepoName;
    }

    public function setGiteaRepoName(?string $giteaRepoName): static
    {
        $this->giteaRepoName = $giteaRepoName;
        return $this;
    }

    public function getGiteaRepoUrl(): ?string
    {
        return $this->giteaRepoUrl;
    }

    public function setGiteaRepoUrl(?string $giteaRepoUrl): static
    {
        $this->giteaRepoUrl = $giteaRepoUrl;
        return $this;
    }

    public function getGiteaWebhookSecret(): ?string
    {
        return $this->giteaWebhookSecret;
    }

    public function setGiteaWebhookSecret(?string $giteaWebhookSecret): static
    {
        $this->giteaWebhookSecret = $giteaWebhookSecret;
        return $this;
    }

    /**
     * Check if project has Gitea repository configured
     */
    public function hasGiteaRepo(): bool
    {
        return $this->giteaRepoId !== null && $this->giteaRepoUrl !== null;
    }

    /**
     * Generate a random webhook secret
     */
    public function generateWebhookSecret(): string
    {
        $this->giteaWebhookSecret = bin2hex(random_bytes(16));
        return $this->giteaWebhookSecret;
    }
}
