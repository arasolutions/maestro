<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

class AnalysisController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection
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

        // Récupérer le cadrage associé
        $cadrage = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.cadrages WHERE analysis_id = :analysisId',
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

        return $this->render('analysis/detail.html.twig', [
            'analysis' => $analysis,
            'cadrage' => $cadrage,
            'userStories' => $userStories,
        ]);
    }
}
