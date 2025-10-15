<?php

namespace App\Service;

use App\Entity\Analysis;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class StatsService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get dashboard statistics
     *
     * @return array Statistics array with counts, averages, distributions
     */
    public function getDashboardStats(): array
    {
        try {
            $conn = $this->entityManager->getConnection();

            // Total analyses count
            $totalAnalyses = (int) $conn->fetchOne('SELECT COUNT(*) FROM maestro.analyses');

            // Average confidence score
            $avgConfidence = (float) $conn->fetchOne('SELECT AVG(confidence) FROM maestro.analyses WHERE confidence IS NOT NULL');

            // Complexity distribution
            $complexityDistribution = $conn->fetchAllAssociative(
                'SELECT complexity, COUNT(*) as count
                FROM maestro.analyses
                WHERE complexity IS NOT NULL
                GROUP BY complexity
                ORDER BY
                    CASE complexity
                        WHEN \'XS\' THEN 1
                        WHEN \'S\' THEN 2
                        WHEN \'M\' THEN 3
                        WHEN \'L\' THEN 4
                        WHEN \'XL\' THEN 5
                        ELSE 6
                    END'
            );

            // Priority distribution
            $priorityDistribution = $conn->fetchAllAssociative(
                'SELECT priority, COUNT(*) as count
                FROM maestro.analyses
                WHERE priority IS NOT NULL
                GROUP BY priority'
            );

            // Recent analyses (last 10)
            $recentAnalyses = $conn->fetchAllAssociative(
                'SELECT id, request_text, complexity, priority, confidence, created_at
                FROM maestro.analyses
                ORDER BY created_at DESC
                LIMIT 10'
            );

            // Analysis type distribution
            $typeDistribution = $conn->fetchAllAssociative(
                'SELECT analysis_type, COUNT(*) as count
                FROM maestro.analyses
                WHERE analysis_type IS NOT NULL
                GROUP BY analysis_type'
            );

            // Total estimated hours
            $totalEstimatedHours = (int) $conn->fetchOne(
                'SELECT SUM(estimated_hours)
                FROM maestro.analyses
                WHERE estimated_hours IS NOT NULL'
            );

            // Analyses by month (last 6 months)
            $analysesByMonth = $conn->fetchAllAssociative(
                "SELECT
                    TO_CHAR(created_at, 'YYYY-MM') as month,
                    COUNT(*) as count
                FROM maestro.analyses
                WHERE created_at >= NOW() - INTERVAL '6 months'
                GROUP BY TO_CHAR(created_at, 'YYYY-MM')
                ORDER BY month DESC"
            );

            return [
                'total_analyses' => $totalAnalyses,
                'average_confidence' => round($avgConfidence, 2),
                'complexity_distribution' => $complexityDistribution,
                'priority_distribution' => $priorityDistribution,
                'type_distribution' => $typeDistribution,
                'recent_analyses' => $recentAnalyses,
                'total_estimated_hours' => $totalEstimatedHours,
                'analyses_by_month' => $analysesByMonth,
                'generated_at' => (new \DateTime())->format('c')
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch dashboard stats', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'error' => true,
                'message' => 'Failed to fetch statistics',
                'total_analyses' => 0,
                'average_confidence' => 0
            ];
        }
    }

    /**
     * Get complexity badge color
     *
     * @param string $complexity Complexity level (XS, S, M, L, XL)
     * @return string Bootstrap badge color class
     */
    public function getComplexityBadgeColor(string $complexity): string
    {
        return match(strtoupper($complexity)) {
            'XS' => 'success',
            'S' => 'info',
            'M' => 'warning',
            'L' => 'orange',
            'XL' => 'danger',
            default => 'secondary'
        };
    }

    /**
     * Get priority badge color
     *
     * @param string $priority Priority level
     * @return string Bootstrap badge color class
     */
    public function getPriorityBadgeColor(string $priority): string
    {
        return match(strtoupper($priority)) {
            'CRITICAL' => 'danger',
            'HIGH' => 'warning',
            'MEDIUM' => 'info',
            'LOW' => 'secondary',
            default => 'secondary'
        };
    }

    /**
     * Get confidence level class
     *
     * @param float $confidence Confidence score (0-1)
     * @return string CSS class for progress bar
     */
    public function getConfidenceClass(float $confidence): string
    {
        if ($confidence >= 0.8) return 'bg-success';
        if ($confidence >= 0.6) return 'bg-info';
        if ($confidence >= 0.4) return 'bg-warning';
        return 'bg-danger';
    }

    /**
     * Count analyses by status/criteria
     *
     * @param array $filters Filters to apply (complexity, priority, etc.)
     * @return int Count of matching analyses
     */
    public function countAnalyses(array $filters = []): int
    {
        try {
            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('COUNT(a.id)')
                ->from(Analysis::class, 'a');

            if (!empty($filters['complexity'])) {
                $qb->andWhere('a.complexity = :complexity')
                   ->setParameter('complexity', $filters['complexity']);
            }

            if (!empty($filters['priority'])) {
                $qb->andWhere('a.priority = :priority')
                   ->setParameter('priority', $filters['priority']);
            }

            return (int) $qb->getQuery()->getSingleScalarResult();

        } catch (\Exception $e) {
            $this->logger->error('Failed to count analyses', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}
