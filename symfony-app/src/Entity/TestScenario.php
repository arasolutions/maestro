<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'test_scenarios', schema: 'maestro')]
class TestScenario
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid', name: 'user_story_id')]
    private Uuid $userStoryId;

    #[ORM\Column(length: 50, name: 'test_type', nullable: true)]
    private ?string $testType = null; // 'unit', 'functional', 'integration'

    #[ORM\Column(type: Types::JSON)]
    private array $scenarios = [];

    #[ORM\Column(type: Types::JSON, name: 'test_data', nullable: true)]
    private ?array $testData = null;

    #[ORM\Column(type: Types::JSON, name: 'expected_results', nullable: true)]
    private ?array $expectedResults = null;

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

    public function getTestType(): ?string
    {
        return $this->testType;
    }

    public function setTestType(?string $testType): static
    {
        $this->testType = $testType;

        return $this;
    }

    public function getScenarios(): array
    {
        return $this->scenarios;
    }

    public function setScenarios(array $scenarios): static
    {
        $this->scenarios = $scenarios;

        return $this;
    }

    public function addScenario(string $name, string $description, array $steps): static
    {
        $this->scenarios[] = [
            'name' => $name,
            'description' => $description,
            'steps' => $steps,
            'status' => 'pending', // 'pending', 'passed', 'failed'
        ];

        return $this;
    }

    public function getTestData(): ?array
    {
        return $this->testData;
    }

    public function setTestData(?array $testData): static
    {
        $this->testData = $testData;

        return $this;
    }

    public function getExpectedResults(): ?array
    {
        return $this->expectedResults;
    }

    public function setExpectedResults(?array $expectedResults): static
    {
        $this->expectedResults = $expectedResults;

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
     * Get count of scenarios
     */
    public function getScenarioCount(): int
    {
        return count($this->scenarios);
    }

    /**
     * Get passed scenarios count
     */
    public function getPassedCount(): int
    {
        return count(array_filter($this->scenarios, fn($s) => ($s['status'] ?? 'pending') === 'passed'));
    }

    /**
     * Get failed scenarios count
     */
    public function getFailedCount(): int
    {
        return count(array_filter($this->scenarios, fn($s) => ($s['status'] ?? 'pending') === 'failed'));
    }

    /**
     * Get test coverage percentage
     */
    public function getCoverage(): float
    {
        $total = $this->getScenarioCount();
        if ($total === 0) {
            return 0.0;
        }

        $passed = $this->getPassedCount();
        return round(($passed / $total) * 100, 2);
    }
}
