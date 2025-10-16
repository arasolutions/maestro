<?php

namespace App\Controller;

use App\Entity\CadrageProposal;
use App\Entity\Project;
use App\Service\CadrageService;
use App\Service\N8nService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

class AnalysisController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly N8nService $n8nService,
        private readonly EntityManagerInterface $entityManager,
        private readonly CadrageService $cadrageService
    ) {
    }

    #[Route('/analyses', name: 'app_analyses_list')]
    public function list(Request $request): Response
    {
        $currentProjectSlug = $request->getSession()->get('current_project_slug');

        if (!$currentProjectSlug) {
            $this->addFlash('warning', 'Veuillez d\'abord sélectionner un projet');
            return $this->redirectToRoute('app_home');
        }

        // Pagination parameters
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        // Filters
        $complexity = $request->query->get('complexity');
        $priority = $request->query->get('priority');
        $search = $request->query->get('search');

        // Get project
        $project = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.projects WHERE slug = :slug',
            ['slug' => $currentProjectSlug]
        );

        if (!$project) {
            throw $this->createNotFoundException('Projet introuvable');
        }

        // Build query
        $sql = "SELECT * FROM maestro.analyses WHERE project_id = :projectId";
        $params = ['projectId' => $project['id']];

        if ($complexity) {
            $sql .= " AND complexity = :complexity";
            $params['complexity'] = $complexity;
        }

        if ($priority) {
            $sql .= " AND priority = :priority";
            $params['priority'] = $priority;
        }

        if ($search) {
            $sql .= " AND request_text ILIKE :search";
            $params['search'] = '%' . $search . '%';
        }

        // Count total
        $countSql = "SELECT COUNT(*) FROM maestro.analyses WHERE project_id = :projectId";
        if ($complexity) $countSql .= " AND complexity = :complexity";
        if ($priority) $countSql .= " AND priority = :priority";
        if ($search) $countSql .= " AND request_text ILIKE :search";

        $total = (int) $this->connection->fetchOne($countSql, $params);

        // Get analyses with pagination
        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $analyses = $this->connection->fetchAllAssociative($sql, $params);

        return $this->render('analysis/list.html.twig', [
            'analyses' => $analyses,
            'project' => $project,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => ceil($total / $limit),
            'filters' => [
                'complexity' => $complexity,
                'priority' => $priority,
                'search' => $search,
            ],
        ]);
    }

    #[Route('/analysis/{id}', name: 'app_analysis_detail')]
    public function detail(string $id): Response
    {
        // Récupérer l'analyse
        $analysis = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.analyses WHERE id = :id',
            ['id' => $id]
        );

        if (!$analysis) {
            throw $this->createNotFoundException('Analyse introuvable');
        }

        // Récupérer le cadrage proposal associé
        $cadrage = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.cadrage_proposals WHERE analysis_id = :analysisId',
            ['analysisId' => $id]
        );

        // Récupérer les user stories associées
        $userStories = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.user_stories WHERE analysis_id = :analysisId',
            ['analysisId' => $id]
        );

        // Décoder les champs JSON
        if ($analysis['agents_needed']) {
            $analysis['agents_needed'] = json_decode($analysis['agents_needed'], true);
        }
        if ($analysis['next_steps']) {
            $analysis['next_steps'] = json_decode($analysis['next_steps'], true);
        }
        if ($analysis['risks']) {
            $analysis['risks'] = json_decode($analysis['risks'], true);
        }
        if ($analysis['full_response']) {
            $analysis['full_response'] = json_decode($analysis['full_response'], true);
        }

        if ($cadrage) {
            $cadrage['perimetre'] = $cadrage['perimetre'] ? json_decode($cadrage['perimetre'], true) : null;
            $cadrage['contraintes'] = $cadrage['contraintes'] ? json_decode($cadrage['contraintes'], true) : null;
            $cadrage['architecture'] = $cadrage['architecture'] ? json_decode($cadrage['architecture'], true) : null;
            $cadrage['swot'] = $cadrage['swot'] ? json_decode($cadrage['swot'], true) : null;
            $cadrage['estimation'] = $cadrage['estimation'] ? json_decode($cadrage['estimation'], true) : null;
        }

        if ($userStories) {
            $userStories['stories'] = $userStories['stories'] ? json_decode($userStories['stories'], true) : null;
            $userStories['acceptance_criteria'] = $userStories['acceptance_criteria'] ? json_decode($userStories['acceptance_criteria'], true) : null;
            $userStories['priority_order'] = $userStories['priority_order'] ? json_decode($userStories['priority_order'], true) : null;
            $userStories['dependencies'] = $userStories['dependencies'] ? json_decode($userStories['dependencies'], true) : null;
        }

        // Récupérer la request associée pour vérifier le statut
        $request = null;
        if ($analysis['request_id']) {
            $request = $this->connection->fetchAssociative(
                'SELECT * FROM maestro.requests WHERE id = :id',
                ['id' => $analysis['request_id']]
            );
        }

        // Récupérer le projet et son cadrage master
        $project = null;
        $projectCadrage = null;
        $projectEntity = null;
        $cadrageProposalEntity = null;
        $diff = null;

        if ($analysis['project_id']) {
            $project = $this->connection->fetchAssociative(
                'SELECT * FROM maestro.projects WHERE id = :id',
                ['id' => $analysis['project_id']]
            );
            if ($project && $project['project_cadrage']) {
                $projectCadrage = json_decode($project['project_cadrage'], true);
            }

            // Get Project entity and CadrageProposal entity for diff calculation
            if ($cadrage && $cadrage['status'] === 'PENDING') {
                $projectEntity = $this->entityManager->getRepository(Project::class)->find(Uuid::fromString($analysis['project_id']));
                $cadrageProposalEntity = $this->entityManager->getRepository(CadrageProposal::class)->find(Uuid::fromString($cadrage['id']));

                if ($projectEntity && $cadrageProposalEntity) {
                    $diff = $this->cadrageService->compareCadrages($projectEntity, $cadrageProposalEntity);
                }
            }
        }

        return $this->render('analysis/detail.html.twig', [
            'analysis' => $analysis,
            'cadrage' => $cadrage,
            'userStories' => $userStories,
            'request' => $request,
            'project' => $project,
            'projectCadrage' => $projectCadrage,
            'diff' => $diff,
        ]);
    }

    /**
     * Lancer manuellement l'orchestration des agents pour une analyse
     */
    #[Route('/analysis/{id}/orchestrate', name: 'app_analysis_orchestrate', methods: ['POST'])]
    public function orchestrate(string $id, Request $request): Response
    {
        // Vérifier le token CSRF
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('orchestrate_analysis_' . $id, $token)) {
            $this->addFlash('error', 'Token CSRF invalide');
            return $this->redirectToRoute('app_analysis_detail', ['id' => $id]);
        }

        try {
            // Récupérer l'analyse
            $analysis = $this->connection->fetchAssociative(
                'SELECT * FROM maestro.analyses WHERE id = :id',
                ['id' => $id]
            );

            if (!$analysis) {
                throw $this->createNotFoundException('Analyse introuvable');
            }

            // Vérifier qu'il y a une request associée
            if (!$analysis['request_id']) {
                $this->addFlash('error', 'Aucune requête associée à cette analyse');
                return $this->redirectToRoute('app_analysis_detail', ['id' => $id]);
            }

            // Récupérer la request pour vérifier le statut
            $requestData = $this->connection->fetchAssociative(
                'SELECT * FROM maestro.requests WHERE id = :id',
                ['id' => $analysis['request_id']]
            );

            if (!$requestData) {
                $this->addFlash('error', 'Requête associée introuvable');
                return $this->redirectToRoute('app_analysis_detail', ['id' => $id]);
            }

            // Vérifier que l'analyse est bien terminée
            if ($requestData['status'] !== 'COMPLETED') {
                $this->addFlash('warning', 'L\'analyse doit être terminée avant de lancer l\'orchestration');
                return $this->redirectToRoute('app_analysis_detail', ['id' => $id]);
            }

            // Appeler l'orchestrateur n8n
            try {
                $response = $this->n8nService->triggerOrchestrator(
                    $id,
                    (string) $analysis['request_id'],
                    (string) $analysis['project_id']
                );

                $agentsExecuted = $response['agents_executed'] ?? [];
                $agentsCount = count($agentsExecuted);

                if ($agentsCount > 0) {
                    $this->addFlash('success', sprintf(
                        'Orchestration lancée avec succès ! %d agent(s) en cours d\'exécution : %s',
                        $agentsCount,
                        implode(', ', $agentsExecuted)
                    ));
                } else {
                    $this->addFlash('info', 'Orchestration lancée, aucun agent supplémentaire requis pour cette analyse');
                }

            } catch (\Exception $e) {
                $this->addFlash('error', 'Erreur lors du lancement de l\'orchestration : ' . $e->getMessage());
            }

            return $this->redirectToRoute('app_analysis_detail', ['id' => $id]);

        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur : ' . $e->getMessage());
            return $this->redirectToRoute('app_analysis_detail', ['id' => $id]);
        }
    }

    /**
     * Accept a cadrage proposal and merge it into the project cadrage
     */
    #[Route('/analysis/cadrage/{proposalId}/accept', name: 'app_cadrage_accept', methods: ['POST'])]
    public function acceptCadrage(string $proposalId, Request $request): Response
    {
        // Verify CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('accept_cadrage_' . $proposalId, $token)) {
            $this->addFlash('error', 'Token CSRF invalide');
            return $this->redirectToRoute('app_analyses_list');
        }

        try {
            // Get the proposal
            $proposal = $this->entityManager->getRepository(CadrageProposal::class)->find(Uuid::fromString($proposalId));

            if (!$proposal) {
                throw $this->createNotFoundException('Proposition de cadrage introuvable');
            }

            // Get the project
            $project = $this->entityManager->getRepository(Project::class)->find($proposal->getProjectId());

            if (!$project) {
                throw $this->createNotFoundException('Projet introuvable');
            }

            // Accept the proposal
            $this->cadrageService->acceptCadrageProposal($project, $proposal);

            $this->addFlash('success', 'Proposition de cadrage acceptée et fusionnée avec le cadrage du projet');

            // Redirect back to analysis detail
            return $this->redirectToRoute('app_analysis_detail', ['id' => $proposal->getAnalysisId()]);

        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de l\'acceptation : ' . $e->getMessage());
            return $this->redirectToRoute('app_analyses_list');
        }
    }

    /**
     * Reject a cadrage proposal
     */
    #[Route('/analysis/cadrage/{proposalId}/reject', name: 'app_cadrage_reject', methods: ['POST'])]
    public function rejectCadrage(string $proposalId, Request $request): Response
    {
        // Verify CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('reject_cadrage_' . $proposalId, $token)) {
            $this->addFlash('error', 'Token CSRF invalide');
            return $this->redirectToRoute('app_analyses_list');
        }

        try {
            // Get the proposal
            $proposal = $this->entityManager->getRepository(CadrageProposal::class)->find(Uuid::fromString($proposalId));

            if (!$proposal) {
                throw $this->createNotFoundException('Proposition de cadrage introuvable');
            }

            // Reject the proposal
            $this->cadrageService->rejectCadrageProposal($proposal);

            $this->addFlash('warning', 'Proposition de cadrage rejetée');

            // Redirect back to analysis detail
            return $this->redirectToRoute('app_analysis_detail', ['id' => $proposal->getAnalysisId()]);

        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors du rejet : ' . $e->getMessage());
            return $this->redirectToRoute('app_analyses_list');
        }
    }

    /**
     * Delete an analysis and all related data (cadrage proposals, user stories)
     */
    #[Route('/analysis/{id}/delete', name: 'app_analysis_delete', methods: ['POST'])]
    public function deleteAnalysis(string $id, Request $request): Response
    {
        // Verify CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete_analysis_' . $id, $token)) {
            $this->addFlash('error', 'Token CSRF invalide');
            return $this->redirectToRoute('app_analyses_list');
        }

        try {
            // Delete related cadrage proposals
            $this->connection->executeStatement(
                'DELETE FROM maestro.cadrage_proposals WHERE analysis_id = :analysisId',
                ['analysisId' => $id]
            );

            // Delete related user stories
            $this->connection->executeStatement(
                'DELETE FROM maestro.user_stories WHERE analysis_id = :analysisId',
                ['analysisId' => $id]
            );

            // Delete the analysis itself
            $deleted = $this->connection->executeStatement(
                'DELETE FROM maestro.analyses WHERE id = :id',
                ['id' => $id]
            );

            if ($deleted > 0) {
                $this->addFlash('success', 'Analyse supprimée avec succès');
            } else {
                $this->addFlash('warning', 'Aucune analyse trouvée avec cet ID');
            }

            return $this->redirectToRoute('app_analyses_list');

        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la suppression : ' . $e->getMessage());
            return $this->redirectToRoute('app_analyses_list');
        }
    }
}
