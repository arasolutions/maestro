<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'generated_code', schema: 'maestro')]
class GeneratedCode
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid', name: 'user_story_id')]
    private Uuid $userStoryId;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $language = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $framework = null;

    #[ORM\Column(type: Types::JSON, name: 'code_files')]
    private array $codeFiles = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $tests = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $documentation = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, name: 'created_at')]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTime();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserStoryId(): Uuid
    {
        return $this->userStoryId;
    }

    public function setUserStoryId(Uuid $userStoryId): static
    {
        $this->userStoryId = $userStoryId;

        return $this;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function getFramework(): ?string
    {
        return $this->framework;
    }

    public function setFramework(?string $framework): static
    {
        $this->framework = $framework;

        return $this;
    }

    public function getCodeFiles(): array
    {
        return $this->codeFiles;
    }

    public function setCodeFiles(array $codeFiles): static
    {
        $this->codeFiles = $codeFiles;

        return $this;
    }

    public function addCodeFile(string $path, string $content, ?string $type = null): static
    {
        $this->codeFiles[] = [
            'path' => $path,
            'content' => $content,
            'type' => $type,
            'lines' => substr_count($content, "\n") + 1,
        ];

        return $this;
    }

    public function getTests(): ?array
    {
        return $this->tests;
    }

    public function setTests(?array $tests): static
    {
        $this->tests = $tests;

        return $this;
    }

    public function getDocumentation(): ?string
    {
        return $this->documentation;
    }

    public function setDocumentation(?string $documentation): static
    {
        $this->documentation = $documentation;

        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * Get total lines of code generated
     */
    public function getTotalLines(): int
    {
        return array_sum(array_column($this->codeFiles, 'lines'));
    }

    /**
     * Get count of files generated
     */
    public function getFileCount(): int
    {
        return count($this->codeFiles);
    }
}
