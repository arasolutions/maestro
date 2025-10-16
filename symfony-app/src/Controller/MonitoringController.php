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

        // Récupérer les requêtes récentes du projet avec leurs analyses
        $recentRequests = $this->connection->fetchAllAssociative(
            "SELECT
                r.id,
                r.request_text,
                r.status as request_status,
                r.webhook_execution_id,
                r.created_at,
                a.id as analysis_id,
                a.complexity,
                a.priority,
                a.confidence
            FROM maestro.requests r
            LEFT JOIN maestro.analyses a ON r.id = a.request_id
            WHERE r.project_id = :projectId
            ORDER BY r.created_at DESC
            LIMIT 20",
            ['projectId' => $project['id']]
        );

        // Mapper le statut des requêtes vers le format de monitoring
        $executions = [];
        foreach ($recentRequests as $req) {
            $createdAt = new \DateTime($req['created_at']);
            $now = new \DateTime();
            $minutesAgo = ($now->getTimestamp() - $createdAt->getTimestamp()) / 60;

            // Mapper le statut de la requête
            $status = 'completed';
            switch ($req['request_status']) {
                case 'PENDING':
                    $status = 'waiting';
                    break;
                case 'PROCESSING':
                    $status = 'running';
                    break;
                case 'COMPLETED':
                    $status = 'completed';
                    break;
                case 'FAILED':
                    $status = 'error';
                    break;
            }

            $executions[] = [
                'id' => $req['analysis_id'] ?: $req['id'], // Utiliser analysis_id si existe, sinon request_id
                'request_id' => $req['id'],
                'request_text' => substr($req['request_text'], 0, 100) . '...',
                'complexity' => $req['complexity'],
                'priority' => $req['priority'],
                'status' => $status,
                'created_at' => $req['created_at'],
                'webhook_id' => $req['webhook_execution_id'],
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

        // Stats des dernières 24h basées sur les requêtes
        $stats = [
            'total_today' => (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM maestro.requests
                WHERE project_id = :projectId
                AND created_at >= NOW() - INTERVAL '24 hours'",
                ['projectId' => $project['id']]
            ),
            'avg_confidence_today' => (float) $this->connection->fetchOne(
                "SELECT AVG(a.confidence)
                FROM maestro.analyses a
                INNER JOIN maestro.requests r ON a.request_id = r.id
                WHERE r.project_id = :projectId
                AND r.created_at >= NOW() - INTERVAL '24 hours'
                AND a.confidence IS NOT NULL",
                ['projectId' => $project['id']]
            ) ?: 0,
            'total_hours_estimated' => (int) $this->connection->fetchOne(
                "SELECT SUM(a.estimated_hours)
                FROM maestro.analyses a
                INNER JOIN maestro.requests r ON a.request_id = r.id
                WHERE r.project_id = :projectId
                AND r.created_at >= NOW() - INTERVAL '24 hours'
                AND a.estimated_hours IS NOT NULL",
                ['projectId' => $project['id']]
            ) ?: 0,
        ];

        return new JsonResponse($stats);
    }
}
