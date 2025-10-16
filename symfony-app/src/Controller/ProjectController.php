<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

class ProjectController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Page de sélection de projet (Homepage)
     */
    #[Route('/', name: 'app_home')]
    public function home(Request $request): Response
    {
        // Si un projet est déjà sélectionné en session, rediriger vers le dashboard
        $currentProjectSlug = $request->getSession()->get('current_project_slug');
        if ($currentProjectSlug) {
            return $this->redirectToRoute('app_project_dashboard', ['slug' => $currentProjectSlug]);
        }

        // Récupérer tous les projets
        $projects = $this->connection->fetchAllAssociative(
            'SELECT
                p.*,
                COUNT(DISTINCT a.id) as analyses_count,
                MAX(a.created_at) as last_analysis_date
            FROM maestro.projects p
            LEFT JOIN maestro.analyses a ON p.id = a.project_id
            GROUP BY p.id
            ORDER BY p.created_at DESC'
        );

        return $this->render('project/home.html.twig', [
            'projects' => $projects,
        ]);
    }

    /**
     * Sélectionner un projet et rediriger vers son dashboard
     */
    #[Route('/project/{slug}/select', name: 'app_project_select')]
    public function select(string $slug, Request $request): Response
    {
        // Vérifier que le projet existe
        $project = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.projects WHERE slug = :slug',
            ['slug' => $slug]
        );

        if (!$project) {
            throw $this->createNotFoundException('Projet introuvable');
        }

        // Stocker le projet en session
        $request->getSession()->set('current_project_slug', $slug);
        $request->getSession()->set('current_project_name', $project['name']);

        $this->addFlash('success', 'Projet "' . $project['name'] . '" sélectionné');

        return $this->redirectToRoute('app_project_dashboard', ['slug' => $slug]);
    }

    /**
     * Dashboard d'un projet spécifique
     */
    #[Route('/project/{slug}', name: 'app_project_dashboard')]
    public function dashboard(string $slug, Request $request): Response
    {
        // Vérifier que le projet existe
        $project = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.projects WHERE slug = :slug',
            ['slug' => $slug]
        );

        if (!$project) {
            throw $this->createNotFoundException('Projet introuvable');
        }

        // Mettre à jour la session
        $request->getSession()->set('current_project_slug', $slug);
        $request->getSession()->set('current_project_name', $project['name']);

        // Récupérer les statistiques du projet
        $stats = $this->getProjectStats($slug);

        return $this->render('project/dashboard.html.twig', [
            'project' => $project,
            'stats' => $stats,
        ]);
    }

    /**
     * Liste de tous les projets (gestion)
     */
    #[Route('/projects', name: 'app_projects_list')]
    public function list(): Response
    {
        $projects = $this->connection->fetchAllAssociative(
            'SELECT
                p.*,
                COUNT(DISTINCT a.id) as analyses_count
            FROM maestro.projects p
            LEFT JOIN maestro.analyses a ON p.id = a.project_id
            GROUP BY p.id
            ORDER BY p.name ASC'
        );

        return $this->render('project/list.html.twig', [
            'projects' => $projects,
        ]);
    }

    /**
     * Créer un nouveau projet
     */
    #[Route('/projects/new', name: 'app_project_new')]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $slug = $request->request->get('slug');
            $description = $request->request->get('description');

            // Validation
            if (empty($name) || empty($slug)) {
                $this->addFlash('error', 'Le nom et le slug sont obligatoires');
                return $this->render('project/new.html.twig');
            }

            // Vérifier l'unicité du slug
            $exists = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM maestro.projects WHERE slug = :slug',
                ['slug' => $slug]
            );

            if ($exists > 0) {
                $this->addFlash('error', 'Un projet avec ce slug existe déjà');
                return $this->render('project/new.html.twig', [
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description,
                ]);
            }

            // Créer le projet
            $this->connection->insert('maestro.projects', [
                'id' => Uuid::v4()->toRfc4122(),
                'slug' => $slug,
                'name' => $name,
                'description' => $description,
                'config' => json_encode([]),
                'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            ]);

            $this->addFlash('success', 'Projet créé avec succès');
            return $this->redirectToRoute('app_projects_list');
        }

        return $this->render('project/new.html.twig');
    }

    /**
     * Éditer un projet
     */
    #[Route('/projects/{slug}/edit', name: 'app_project_edit')]
    public function edit(string $slug, Request $request): Response
    {
        $project = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.projects WHERE slug = :slug',
            ['slug' => $slug]
        );

        if (!$project) {
            throw $this->createNotFoundException('Projet introuvable');
        }

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $description = $request->request->get('description');

            if (empty($name)) {
                $this->addFlash('error', 'Le nom est obligatoire');
                return $this->render('project/edit.html.twig', ['project' => $project]);
            }

            $this->connection->update('maestro.projects', [
                'name' => $name,
                'description' => $description,
            ], ['slug' => $slug]);

            $this->addFlash('success', 'Projet modifié avec succès');
            return $this->redirectToRoute('app_projects_list');
        }

        return $this->render('project/edit.html.twig', [
            'project' => $project,
        ]);
    }

    /**
     * Supprimer un projet
     */
    #[Route('/projects/{slug}/delete', name: 'app_project_delete', methods: ['POST'])]
    public function delete(string $slug, Request $request): Response
    {
        $project = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.projects WHERE slug = :slug',
            ['slug' => $slug]
        );

        if (!$project) {
            throw $this->createNotFoundException('Projet introuvable');
        }

        // Vérifier le token CSRF
        $submittedToken = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete' . $slug, $submittedToken)) {
            $this->addFlash('error', 'Token CSRF invalide');
            return $this->redirectToRoute('app_projects_list');
        }

        $this->connection->delete('maestro.projects', ['slug' => $slug]);

        // Supprimer de la session si c'était le projet actif
        if ($request->getSession()->get('current_project_slug') === $slug) {
            $request->getSession()->remove('current_project_slug');
            $request->getSession()->remove('current_project_name');
        }

        $this->addFlash('success', 'Projet supprimé avec succès');
        return $this->redirectToRoute('app_home');
    }

    /**
     * Récupérer les statistiques d'un projet
     */
    private function getProjectStats(string $projectSlug): array
    {
        // Récupérer l'ID du projet
        $project = $this->connection->fetchAssociative(
            'SELECT id FROM maestro.projects WHERE slug = :slug',
            ['slug' => $projectSlug]
        );

        if (!$project) {
            return $this->getEmptyStats();
        }

        $projectId = $project['id'];

        // Total analyses pour ce projet
        $totalAnalyses = (int) $this->connection->fetchOne(
            "SELECT COUNT(*)
            FROM maestro.analyses
            WHERE project_id = :projectId",
            ['projectId' => $projectId]
        );

        // Total requests et stats par statut
        $requestsStats = $this->connection->fetchAllAssociative(
            "SELECT status, COUNT(*) as count
            FROM maestro.requests
            WHERE project_id = :projectId
            GROUP BY status",
            ['projectId' => $projectId]
        );

        $totalRequests = (int) $this->connection->fetchOne(
            "SELECT COUNT(*)
            FROM maestro.requests
            WHERE project_id = :projectId",
            ['projectId' => $projectId]
        );

        // Récupérer les requêtes récentes (toutes statuts confondus)
        $recentRequests = $this->connection->fetchAllAssociative(
            "SELECT id, request_text, request_type, priority, status, created_at, updated_at
            FROM maestro.requests
            WHERE project_id = :projectId
            ORDER BY created_at DESC
            LIMIT 10",
            ['projectId' => $projectId]
        );

        // Confiance moyenne
        $avgConfidence = (float) $this->connection->fetchOne(
            "SELECT AVG(confidence)
            FROM maestro.analyses
            WHERE project_id = :projectId
            AND confidence IS NOT NULL",
            ['projectId' => $projectId]
        );

        // Distribution complexité
        $complexityDistribution = $this->connection->fetchAllAssociative(
            "SELECT complexity, COUNT(*) as count
            FROM maestro.analyses
            WHERE project_id = :projectId
            AND complexity IS NOT NULL
            GROUP BY complexity
            ORDER BY
                CASE complexity
                    WHEN 'XS' THEN 1
                    WHEN 'S' THEN 2
                    WHEN 'M' THEN 3
                    WHEN 'L' THEN 4
                    WHEN 'XL' THEN 5
                    ELSE 6
                END",
            ['projectId' => $projectId]
        );

        // Distribution priorité
        $priorityDistribution = $this->connection->fetchAllAssociative(
            "SELECT priority, COUNT(*) as count
            FROM maestro.analyses
            WHERE project_id = :projectId
            AND priority IS NOT NULL
            GROUP BY priority",
            ['projectId' => $projectId]
        );

        // Analyses récentes
        $recentAnalyses = $this->connection->fetchAllAssociative(
            "SELECT id, request_text, complexity, priority, confidence, created_at
            FROM maestro.analyses
            WHERE project_id = :projectId
            ORDER BY created_at DESC
            LIMIT 10",
            ['projectId' => $projectId]
        );

        // Total heures estimées
        $totalEstimatedHours = (int) $this->connection->fetchOne(
            "SELECT SUM(estimated_hours)
            FROM maestro.analyses
            WHERE project_id = :projectId
            AND estimated_hours IS NOT NULL",
            ['projectId' => $projectId]
        );

        // Distribution par type
        $typeDistribution = $this->connection->fetchAllAssociative(
            "SELECT analysis_type, COUNT(*) as count
            FROM maestro.analyses
            WHERE project_id = :projectId
            AND analysis_type IS NOT NULL
            GROUP BY analysis_type",
            ['projectId' => $projectId]
        );

        // Analyses par mois
        $analysesByMonth = $this->connection->fetchAllAssociative(
            "SELECT
                TO_CHAR(created_at, 'YYYY-MM') as month,
                COUNT(*) as count
            FROM maestro.analyses
            WHERE project_id = :projectId
            AND created_at >= NOW() - INTERVAL '6 months'
            GROUP BY TO_CHAR(created_at, 'YYYY-MM')
            ORDER BY month DESC",
            ['projectId' => $projectId]
        );

        return [
            'total_analyses' => $totalAnalyses,
            'total_requests' => $totalRequests,
            'requests_stats' => $requestsStats,
            'recent_requests' => $recentRequests,
            'average_confidence' => round($avgConfidence, 2),
            'complexity_distribution' => $complexityDistribution,
            'priority_distribution' => $priorityDistribution,
            'type_distribution' => $typeDistribution,
            'recent_analyses' => $recentAnalyses,
            'total_estimated_hours' => $totalEstimatedHours,
            'analyses_by_month' => $analysesByMonth,
            'generated_at' => (new \DateTime())->format('c')
        ];
    }

    /**
     * Retourne des stats vides
     */
    private function getEmptyStats(): array
    {
        return [
            'total_analyses' => 0,
            'total_requests' => 0,
            'requests_stats' => [],
            'recent_requests' => [],
            'average_confidence' => 0,
            'complexity_distribution' => [],
            'priority_distribution' => [],
            'type_distribution' => [],
            'recent_analyses' => [],
            'total_estimated_hours' => 0,
            'analyses_by_month' => [],
            'generated_at' => (new \DateTime())->format('c')
        ];
    }
}
