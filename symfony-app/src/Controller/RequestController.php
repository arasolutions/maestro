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

                    // Enregistrer le webhook_execution_id si retourné par n8n
                    if (isset($response['execution_id'])) {
                        $this->connection->update('maestro.requests',
                            ['webhook_execution_id' => $response['execution_id']],
                            ['id' => $requestId],
                            ['id' => 'uuid']
                        );
                    }

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
}
