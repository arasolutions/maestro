<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MonitoringController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly HttpClientInterface $httpClient
    ) {
    }

    #[Route('/monitoring', name: 'app_monitoring')]
    public function index(Request $request): Response
    {
        $currentProjectSlug = $request->getSession()->get('current_project_slug');

        if (!$currentProjectSlug) {
            $this->addFlash('warning', 'Veuillez d\'abord sélectionner un projet');
            return $this->redirectToRoute('app_home');
        }

        $project = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.projects WHERE slug = :slug',
            ['slug' => $currentProjectSlug]
        );

        if (!$project) {
            throw $this->createNotFoundException('Projet introuvable');
        }

        return $this->render('monitoring/index.html.twig', [
            'project' => $project,
        ]);
    }

    /**
     * API pour récupérer les exécutions en cours depuis n8n
     */
    #[Route('/api/monitoring/executions', name: 'app_api_monitoring_executions', methods: ['GET'])]
    public function getExecutions(Request $request): JsonResponse
    {
        $currentProjectSlug = $request->getSession()->get('current_project_slug');

        if (!$currentProjectSlug) {
            return new JsonResponse(['error' => 'No project selected'], 400);
        }

        $project = $this->connection->fetchAssociative(
            'SELECT id FROM maestro.projects WHERE slug = :slug',
            ['slug' => $currentProjectSlug]
        );

        if (!$project) {
            return new JsonResponse(['error' => 'Project not found'], 404);
        }

        // Récupérer les analyses récentes du projet
        $recentAnalyses = $this->connection->fetchAllAssociative(
            "SELECT
                id,
                request_text,
                complexity,
                priority,
                confidence,
                webhook_execution_id,
                created_at
            FROM maestro.analyses
            WHERE project_id = :projectId
            ORDER BY created_at DESC
            LIMIT 20",
            ['projectId' => $project['id']]
        );

        // Récupérer le statut depuis n8n (si webhook_execution_id existe)
        $executions = [];
        foreach ($recentAnalyses as $analysis) {
            $status = 'completed'; // Par défaut
            $duration = null;

            // Si on a un webhook_execution_id, on pourrait appeler l'API n8n pour récupérer le statut
            // Pour l'instant, on simule le statut en fonction de l'heure de création
            $createdAt = new \DateTime($analysis['created_at']);
            $now = new \DateTime();
            $minutesAgo = ($now->getTimestamp() - $createdAt->getTimestamp()) / 60;

            if ($minutesAgo < 5) {
                $status = 'running';
            } elseif ($minutesAgo < 15 && !$analysis['confidence']) {
                $status = 'waiting';
            }

            $executions[] = [
                'id' => $analysis['id'],
                'request_text' => substr($analysis['request_text'], 0, 100) . '...',
                'complexity' => $analysis['complexity'],
                'priority' => $analysis['priority'],
                'status' => $status,
                'created_at' => $analysis['created_at'],
                'webhook_id' => $analysis['webhook_execution_id'],
                'minutes_ago' => round($minutesAgo),
            ];
        }

        return new JsonResponse([
            'executions' => $executions,
            'stats' => [
                'running' => count(array_filter($executions, fn($e) => $e['status'] === 'running')),
                'waiting' => count(array_filter($executions, fn($e) => $e['status'] === 'waiting')),
                'completed' => count(array_filter($executions, fn($e) => $e['status'] === 'completed')),
            ]
        ]);
    }

    /**
     * API pour récupérer les stats en temps réel
     */
    #[Route('/api/monitoring/stats', name: 'app_api_monitoring_stats', methods: ['GET'])]
    public function getStats(Request $request): JsonResponse
    {
        $currentProjectSlug = $request->getSession()->get('current_project_slug');

        if (!$currentProjectSlug) {
            return new JsonResponse(['error' => 'No project selected'], 400);
        }

        $project = $this->connection->fetchAssociative(
            'SELECT id FROM maestro.projects WHERE slug = :slug',
            ['slug' => $currentProjectSlug]
        );

        if (!$project) {
            return new JsonResponse(['error' => 'Project not found'], 404);
        }

        // Stats des dernières 24h
        $stats = [
            'total_today' => (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM maestro.analyses
                WHERE project_id = :projectId
                AND created_at >= NOW() - INTERVAL '24 hours'",
                ['projectId' => $project['id']]
            ),
            'avg_confidence_today' => (float) $this->connection->fetchOne(
                "SELECT AVG(confidence) FROM maestro.analyses
                WHERE project_id = :projectId
                AND created_at >= NOW() - INTERVAL '24 hours'
                AND confidence IS NOT NULL",
                ['projectId' => $project['id']]
            ) ?: 0,
            'total_hours_estimated' => (int) $this->connection->fetchOne(
                "SELECT SUM(estimated_hours) FROM maestro.analyses
                WHERE project_id = :projectId
                AND created_at >= NOW() - INTERVAL '24 hours'
                AND estimated_hours IS NOT NULL",
                ['projectId' => $project['id']]
            ) ?: 0,
        ];

        return new JsonResponse($stats);
    }
}
