<?php

namespace App\Service;

use App\Entity\CadrageProposal;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

class CadrageService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Compare project cadrage with a proposal and return differences
     */
    public function compareCadrages(Project $project, CadrageProposal $proposal): array
    {
        $current = $project->getProjectCadrage() ?? [];
        $proposed = $proposal->toArray();

        $diff = [
            'added' => [],
            'modified' => [],
            'removed' => [],
            'unchanged' => []
        ];

        // Check all proposed keys
        foreach ($proposed as $key => $proposedValue) {
            if (!isset($current[$key])) {
                $diff['added'][$key] = $proposedValue;
            } elseif (json_encode($current[$key]) !== json_encode($proposedValue)) {
                $diff['modified'][$key] = [
                    'old' => $current[$key],
                    'new' => $proposedValue
                ];
            } else {
                $diff['unchanged'][$key] = $proposedValue;
            }
        }

        // Check for removed keys
        foreach ($current as $key => $currentValue) {
            if (!isset($proposed[$key])) {
                $diff['removed'][$key] = $currentValue;
            }
        }

        return $diff;
    }

    /**
     * Accept and merge a cadrage proposal into the project
     */
    public function acceptCadrageProposal(Project $project, CadrageProposal $proposal): void
    {
        // Merge the proposal into project cadrage
        $project->mergeCadrageProposal($proposal->toArray());

        // Mark proposal as accepted
        $proposal->accept();

        // Persist changes
        $this->entityManager->persist($project);
        $this->entityManager->persist($proposal);
        $this->entityManager->flush();
    }

    /**
     * Reject a cadrage proposal
     */
    public function rejectCadrageProposal(CadrageProposal $proposal): void
    {
        $proposal->reject();
        $this->entityManager->persist($proposal);
        $this->entityManager->flush();
    }

    /**
     * Update project cadrage manually
     */
    public function updateProjectCadrage(Project $project, array $cadrageData): void
    {
        $project->setProjectCadrage($cadrageData);
        $this->entityManager->persist($project);
        $this->entityManager->flush();
    }

    /**
     * Get pending proposals for a project
     */
    public function getPendingProposals(Project $project): array
    {
        return $this->entityManager->getRepository(CadrageProposal::class)
            ->createQueryBuilder('cp')
            ->where('cp.projectId = :projectId')
            ->andWhere('cp.status = :status')
            ->setParameter('projectId', $project->getId(), 'uuid')
            ->setParameter('status', 'PENDING')
            ->orderBy('cp.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get all proposals for a project (for history)
     */
    public function getAllProposals(Project $project): array
    {
        return $this->entityManager->getRepository(CadrageProposal::class)
            ->createQueryBuilder('cp')
            ->where('cp.projectId = :projectId')
            ->setParameter('projectId', $project->getId(), 'uuid')
            ->orderBy('cp.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Auto-accept first cadrage proposal for a project (when project has no cadrage yet)
     */
    public function autoAcceptFirstProposal(Project $project, CadrageProposal $proposal): void
    {
        if (!$project->hasCadrage()) {
            $this->acceptCadrageProposal($project, $proposal);
        }
    }
}
