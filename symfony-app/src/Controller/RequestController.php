<?php

namespace App\Controller;

use App\Service\N8nService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

class RequestController extends AbstractController
{
    public function __construct(
        private readonly N8nService $n8nService,
        private readonly Connection $connection
    ) {
    }

    /**
     * Liste de toutes les requêtes
     */
    #[Route('/requests', name: 'app_requests_list')]
    public function list(Request $request): Response
    {
        // Récupérer le projet courant si sélectionné
        $currentProjectSlug = $request->getSession()->get('current_project_slug');

        // Filtres
        $status = $request->query->get('status');
        $projectSlug = $request->query->get('project', $currentProjectSlug);

        // Construire la requête SQL
        $sql = 'SELECT r.id, r.request_text, r.status, r.project_id,
                       r.webhook_execution_id, r.error_message, r.created_at, r.updated_at,
                       p.name as project_name, p.slug as project_slug,
                       a.id as analysis_id, a.complexity, a.confidence,
                       a.analysis_type, a.priority
                FROM maestro.requests r
                LEFT JOIN maestro.projects p ON r.project_id = p.id
                LEFT JOIN maestro.analyses a ON r.id = a.request_id
                WHERE 1=1';

        $params = [];

        if ($projectSlug) {
            $sql .= ' AND p.slug = :project_slug';
            $params['project_slug'] = $projectSlug;
        }

        if ($status) {
            $sql .= ' AND r.status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY r.created_at DESC';

        $requests = $this->connection->fetchAllAssociative($sql, $params);

        // Récupérer tous les projets pour le filtre
        $projects = $this->connection->fetchAllAssociative(
            'SELECT slug, name FROM maestro.projects ORDER BY name ASC'
        );

        // Statistiques
        $stats = [
            'total' => count($requests),
            'pending' => count(array_filter($requests, fn($r) => $r['status'] === 'PENDING')),
            'processing' => count(array_filter($requests, fn($r) => $r['status'] === 'PROCESSING')),
            'completed' => count(array_filter($requests, fn($r) => $r['status'] === 'COMPLETED')),
            'failed' => count(array_filter($requests, fn($r) => $r['status'] === 'FAILED')),
        ];

        return $this->render('request/list.html.twig', [
            'requests' => $requests,
            'projects' => $projects,
            'current_project_slug' => $projectSlug,
            'current_status' => $status,
            'stats' => $stats,
        ]);
    }

    /**
     * Supprimer une requête
     */
    #[Route('/request/{id}/delete', name: 'app_request_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        // Vérifier le token CSRF
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete_request_' . $id, $token)) {
            $this->addFlash('error', 'Token CSRF invalide');
            return $this->redirectToRoute('app_requests_list');
        }

        try {
            // Supprimer la requête (cascade supprimera aussi l'analyse associée)
            $this->connection->delete('maestro.requests', ['id' => $id]);

            $this->addFlash('success', 'Requête supprimée avec succès');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la suppression: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_requests_list');
    }

    /**
     * Supprimer plusieurs requêtes en masse
     */
    #[Route('/requests/bulk-delete', name: 'app_requests_bulk_delete', methods: ['POST'])]
    public function bulkDelete(Request $request): Response
    {
        // Vérifier le token CSRF
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('bulk_delete_requests', $token)) {
            $this->addFlash('error', 'Token CSRF invalide');
            return $this->redirectToRoute('app_requests_list');
        }

        $requestIds = $request->request->all('request_ids');

        if (empty($requestIds)) {
            $this->addFlash('warning', 'Aucune requête sélectionnée');
            return $this->redirectToRoute('app_requests_list');
        }

        try {
            $count = 0;
            foreach ($requestIds as $id) {
                $this->connection->delete('maestro.requests', ['id' => $id]);
                $count++;
            }

            $this->addFlash('success', sprintf('%d requête(s) supprimée(s) avec succès', $count));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la suppression en masse: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_requests_list');
    }

    /**
     * Formulaire de soumission d'une nouvelle requête
     */
    #[Route('/request/new', name: 'app_request_new')]
    public function new(Request $request): Response
    {
        // Vérifier qu'un projet est sélectionné
        $currentProjectSlug = $request->getSession()->get('current_project_slug');
        if (!$currentProjectSlug) {
            $this->addFlash('warning', 'Veuillez d\'abord sélectionner un projet');
            return $this->redirectToRoute('app_home');
        }

        $currentProjectName = $request->getSession()->get('current_project_name');

        if ($request->isMethod('POST')) {
            // Vérifier le token CSRF
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('request_form', $token)) {
                $this->addFlash('error', 'Token CSRF invalide. Veuillez réessayer.');
                return $this->redirectToRoute('app_request_new');
            }

            $description = $request->request->get('description');

            // Validation
            if (empty($description)) {
                $this->addFlash('error', 'La description est obligatoire');
                return $this->render('request/new.html.twig', [
                    'project_name' => $currentProjectName,
                    'description' => $description,
                ]);
            }

            try {
                // Récupérer l'ID du projet
                $projectId = $this->connection->fetchOne(
                    'SELECT id FROM maestro.projects WHERE slug = :slug',
                    ['slug' => $currentProjectSlug]
                );

                // Vérifier que le projet existe
                if (!$projectId) {
                    $this->addFlash('error', 'Projet introuvable. Veuillez sélectionner un projet existant.');
                    return $this->redirectToRoute('app_home');
                }

                // S'assurer que current_project_id est en session
                if (!$request->getSession()->get('current_project_id')) {
                    $request->getSession()->set('current_project_id', $projectId);
                }

                // Créer la request dans la base de données (description brute uniquement)
                $requestId = Uuid::v4();

                $this->connection->insert('maestro.requests', [
                    'id' => $requestId,
                    'request_text' => $description,
                    'status' => 'PENDING',
                    'project_id' => $projectId,
                    'created_at' => new \DateTime(),
                ], [
                    'id' => 'uuid',
                    'project_id' => 'uuid',
                    'created_at' => 'datetime',
                ]);

                // Préparer le payload pour n8n avec le request_id
                // L'orchestrator déterminera automatiquement type, priorité, complexité
                $metadata = [
                    'request_id' => (string) $requestId,
                    'project_id' => (string) $projectId,
                    'project_slug' => $currentProjectSlug,
                    'project_name' => $currentProjectName,
                ];

                // Appeler l'agent Analyzer via webhook n8n
                try {
                    // Mettre à jour le status à PROCESSING
                    $this->connection->update('maestro.requests',
                        ['status' => 'PROCESSING', 'updated_at' => new \DateTime()],
                        ['id' => $requestId],
                        ['id' => 'uuid', 'updated_at' => 'datetime']
                    );

                    // Appeler n8n orchestrator
                    $response = $this->n8nService->triggerOrchestration($description, $metadata);

                    // Si succès, mettre à jour le statut à COMPLETED
                    $this->connection->update('maestro.requests',
                        [
                            'status' => 'COMPLETED',
                            'webhook_execution_id' => $response['execution_id'] ?? null,
                            'updated_at' => new \DateTime()
                        ],
                        ['id' => $requestId],
                        ['id' => 'uuid', 'updated_at' => 'datetime']
                    );

                    $this->addFlash('success', 'Votre requête a été soumise avec succès et est en cours d\'analyse par les agents IA');

                } catch (\Exception $webhookError) {
                    // Si l'appel à n8n échoue, marquer la request comme FAILED mais continuer
                    $this->connection->update('maestro.requests',
                        [
                            'status' => 'FAILED',
                            'error_message' => $webhookError->getMessage(),
                            'updated_at' => new \DateTime()
                        ],
                        ['id' => $requestId],
                        ['id' => 'uuid', 'updated_at' => 'datetime']
                    );

                    $this->addFlash('warning', 'Requête créée mais l\'agent Analyzer n8n n\'a pas pu être contacté: ' . $webhookError->getMessage());
                    // Ne pas throw pour permettre de voir la requête créée même si n8n échoue
                }

                // Rediriger vers la page de suivi de la requête
                return $this->redirectToRoute('app_request_detail', ['id' => (string) $requestId]);

            } catch (\Exception $e) {
                $this->addFlash('error', 'Erreur lors de la soumission: ' . $e->getMessage());
                return $this->render('request/new.html.twig', [
                    'project_name' => $currentProjectName,
                    'description' => $description,
                ]);
            }
        }

        return $this->render('request/new.html.twig', [
            'project_name' => $currentProjectName,
        ]);
    }

    /**
     * Page de détail d'une requête avec suivi du statut
     */
    #[Route('/request/{id}', name: 'app_request_detail')]
    public function detail(string $id): Response
    {
        try {
            // Récupérer la requête
            $request = $this->connection->fetchAssociative(
                'SELECT r.*, p.slug as project_slug, p.name as project_name
                 FROM maestro.requests r
                 LEFT JOIN maestro.projects p ON r.project_id = p.id
                 WHERE r.id = :id',
                ['id' => $id]
            );

            if (!$request) {
                throw $this->createNotFoundException('Requête non trouvée');
            }

            // Vérifier si une analyse a été créée
            $analysis = null;
            if ($request['status'] === 'COMPLETED') {
                $analysis = $this->connection->fetchAssociative(
                    'SELECT * FROM maestro.analyses WHERE request_id = :request_id',
                    ['request_id' => $id]
                );
            }

            return $this->render('request/detail.html.twig', [
                'request' => $request,
                'analysis' => $analysis,
            ]);

        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur: ' . $e->getMessage());
            return $this->redirectToRoute('app_home');
        }
    }

    /**
     * Relancer l'analyse d'une requête échouée
     */
    #[Route('/request/{id}/retry', name: 'app_request_retry', methods: ['POST'])]
    public function retry(string $id, Request $request): Response
    {
        // Vérifier le token CSRF
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('retry_request_' . $id, $token)) {
            $this->addFlash('error', 'Token CSRF invalide');
            return $this->redirectToRoute('app_request_detail', ['id' => $id]);
        }

        try {
            // Récupérer la requête
            $requestData = $this->connection->fetchAssociative(
                'SELECT r.*, p.slug as project_slug, p.name as project_name
                 FROM maestro.requests r
                 LEFT JOIN maestro.projects p ON r.project_id = p.id
                 WHERE r.id = :id',
                ['id' => $id]
            );

            if (!$requestData) {
                throw $this->createNotFoundException('Requête introuvable');
            }

            // Vérifier que le statut est FAILED ou COMPLETED
            if (!in_array($requestData['status'], ['FAILED', 'COMPLETED'])) {
                $this->addFlash('warning', 'Seules les requêtes terminées ou échouées peuvent être relancées');
                return $this->redirectToRoute('app_request_detail', ['id' => $id]);
            }

            // Supprimer les analyses, cadrages et user stories existants pour cette requête
            $this->connection->beginTransaction();
            try {
                // Récupérer les IDs des analyses existantes
                $analysisIds = $this->connection->fetchFirstColumn(
                    'SELECT id FROM maestro.analyses WHERE request_id = :requestId',
                    ['requestId' => $id]
                );

                if (!empty($analysisIds)) {
                    // Supprimer les user stories
                    $this->connection->executeStatement(
                        'DELETE FROM maestro.user_stories WHERE analysis_id = ANY(:ids)',
                        ['ids' => $analysisIds],
                        ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING]
                    );

                    // Supprimer les cadrages
                    $this->connection->executeStatement(
                        'DELETE FROM maestro.cadrage_proposals WHERE analysis_id = ANY(:ids)',
                        ['ids' => $analysisIds],
                        ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING]
                    );

                    // Supprimer les analyses
                    $this->connection->executeStatement(
                        'DELETE FROM maestro.analyses WHERE id = ANY(:ids)',
                        ['ids' => $analysisIds],
                        ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING]
                    );
                }

                // Mettre à jour le statut à PROCESSING
                $this->connection->update('maestro.requests',
                    [
                        'status' => 'PROCESSING',
                        'error_message' => null,
                        'updated_at' => new \DateTime()
                    ],
                    ['id' => $id],
                    ['id' => 'uuid', 'updated_at' => 'datetime']
                );

                $this->connection->commit();
            } catch (\Exception $e) {
                $this->connection->rollBack();
                throw $e;
            }

            // Préparer les métadonnées
            $metadata = [
                'request_id' => $id,
                'project_id' => $requestData['project_id'],
                'project_slug' => $requestData['project_slug'],
                'project_name' => $requestData['project_name'],
            ];

            // Appeler l'agent Analyzer via n8n
            try {
                $response = $this->n8nService->triggerOrchestration($requestData['request_text'], $metadata);

                // Si succès, mettre à jour le statut à COMPLETED
                $this->connection->update('maestro.requests',
                    [
                        'status' => 'COMPLETED',
                        'webhook_execution_id' => $response['execution_id'] ?? null,
                        'updated_at' => new \DateTime()
                    ],
                    ['id' => $id],
                    ['id' => 'uuid', 'updated_at' => 'datetime']
                );

                $this->addFlash('success', 'L\'analyse a été relancée avec succès');

            } catch (\Exception $webhookError) {
                // Si l'appel à n8n échoue, marquer comme FAILED
                $this->connection->update('maestro.requests',
                    [
                        'status' => 'FAILED',
                        'error_message' => $webhookError->getMessage(),
                        'updated_at' => new \DateTime()
                    ],
                    ['id' => $id],
                    ['id' => 'uuid', 'updated_at' => 'datetime']
                );

                $this->addFlash('error', 'Échec de la relance: ' . $webhookError->getMessage());
            }

        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la relance: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_request_detail', ['id' => $id]);
    }
}
