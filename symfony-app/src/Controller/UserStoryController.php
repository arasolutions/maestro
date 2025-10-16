<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class UserStoryController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * List all user stories for a project
     */
    #[Route('/project/{projectId}/user-stories', name: 'app_project_user_stories')]
    public function listByProject(string $projectId, Request $request): Response
    {
        // Get the project
        $project = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.projects WHERE id = :id',
            ['id' => $projectId]
        );

        if (!$project) {
            throw $this->createNotFoundException('Projet introuvable');
        }

        // Get filters
        $filters = [
            'status' => $request->query->get('status', ''),
            'priority' => $request->query->get('priority', ''),
            'search' => $request->query->get('search', ''),
        ];

        // Build query
        $sql = 'SELECT us.*, a.request_text, a.complexity, a.created_at as analysis_date
                FROM maestro.user_stories us
                INNER JOIN maestro.analyses a ON us.analysis_id = a.id
                WHERE a.project_id = :projectId';

        $params = ['projectId' => $projectId];

        // Note: status filtering would require a status column in user_stories table
        // For now, we'll get all stories and filter in the template if needed

        $sql .= ' ORDER BY us.created_at DESC';

        $userStoriesRaw = $this->connection->fetchAllAssociative($sql, $params);

        // Decode JSONB fields and flatten stories
        $allStories = [];
        foreach ($userStoriesRaw as $us) {
            $stories = json_decode($us['stories'], true);
            if (is_array($stories)) {
                foreach ($stories as $story) {
                    $allStories[] = [
                        'id' => $story['id'] ?? 'N/A',
                        'title' => $story['title'] ?? 'Sans titre',
                        'priority' => $story['priority'] ?? 'COULD',
                        'story_points' => $story['story_points'] ?? 0,
                        'as_a' => $story['as_a'] ?? '',
                        'i_want' => $story['i_want'] ?? '',
                        'so_that' => $story['so_that'] ?? '',
                        'acceptance_criteria' => $story['acceptance_criteria'] ?? [],
                        'test_scenarios' => $story['test_scenarios'] ?? [],
                        'dependencies' => $story['dependencies'] ?? [],
                        'technical_notes' => $story['technical_notes'] ?? '',
                        'status' => $story['status'] ?? 'TODO', // Default status
                        'analysis_id' => $us['analysis_id'],
                        'request_text' => $us['request_text'],
                        'complexity' => $us['complexity'],
                        'analysis_date' => $us['analysis_date'],
                    ];
                }
            }
        }

        // Apply filters
        if ($filters['priority']) {
            $allStories = array_filter($allStories, fn($s) => $s['priority'] === $filters['priority']);
        }

        if ($filters['status']) {
            $allStories = array_filter($allStories, fn($s) => $s['status'] === $filters['status']);
        }

        if ($filters['search']) {
            $search = strtolower($filters['search']);
            $allStories = array_filter($allStories, function($s) use ($search) {
                return str_contains(strtolower($s['title']), $search) ||
                       str_contains(strtolower($s['i_want']), $search) ||
                       str_contains(strtolower($s['as_a']), $search);
            });
        }

        // Calculate statistics
        $stats = [
            'total' => count($allStories),
            'by_priority' => [
                'MUST' => count(array_filter($allStories, fn($s) => $s['priority'] === 'MUST')),
                'SHOULD' => count(array_filter($allStories, fn($s) => $s['priority'] === 'SHOULD')),
                'COULD' => count(array_filter($allStories, fn($s) => $s['priority'] === 'COULD')),
                'WONT' => count(array_filter($allStories, fn($s) => $s['priority'] === 'WONT')),
            ],
            'by_status' => [
                'TODO' => count(array_filter($allStories, fn($s) => $s['status'] === 'TODO')),
                'IN_PROGRESS' => count(array_filter($allStories, fn($s) => $s['status'] === 'IN_PROGRESS')),
                'DONE' => count(array_filter($allStories, fn($s) => $s['status'] === 'DONE')),
            ],
            'total_points' => array_sum(array_column($allStories, 'story_points')),
        ];

        return $this->render('user_story/list.html.twig', [
            'project' => $project,
            'stories' => $allStories,
            'filters' => $filters,
            'stats' => $stats,
        ]);
    }
}
