<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CIController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * View CI builds for a user story
     */
    #[Route('/user-story/{analysisId}/{storyId}/builds', name: 'app_user_story_builds')]
    public function viewBuilds(string $analysisId, string $storyId): Response
    {
        // Get user story record
        $userStoriesRecord = $this->connection->fetchAssociative(
            'SELECT id, stories FROM maestro.user_stories WHERE analysis_id = :analysisId',
            ['analysisId' => $analysisId]
        );

        if (!$userStoriesRecord) {
            throw $this->createNotFoundException('User stories introuvables');
        }

        $stories = json_decode($userStoriesRecord['stories'], true);
        $story = null;

        foreach ($stories as $s) {
            if ($s['id'] === $storyId) {
                $story = $s;
                break;
            }
        }

        if (!$story) {
            throw $this->createNotFoundException('User story introuvable');
        }

        // Get analysis and project
        $analysis = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.analyses WHERE id = :id',
            ['id' => $analysisId]
        );

        $project = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.projects WHERE id = :id',
            ['id' => $analysis['project_id']]
        );

        // Get git branch
        $gitBranch = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.git_branches WHERE user_story_id = :userStoryId ORDER BY created_at DESC LIMIT 1',
            ['userStoryId' => $userStoriesRecord['id']]
        );

        // Get all builds for this branch
        $builds = [];
        if ($gitBranch) {
            $builds = $this->connection->fetchAllAssociative(
                'SELECT * FROM maestro.ci_builds WHERE branch_id = :branchId ORDER BY created_at DESC',
                ['branchId' => $gitBranch['id']]
            );

            // Decode JSON fields
            foreach ($builds as &$build) {
                if ($build['test_results']) {
                    $build['test_results'] = json_decode($build['test_results'], true);
                }
            }
        }

        return $this->render('ci/builds.html.twig', [
            'story' => $story,
            'analysis' => $analysis,
            'analysisId' => $analysisId,
            'storyId' => $storyId,
            'project' => $project,
            'gitBranch' => $gitBranch,
            'builds' => $builds,
        ]);
    }

    /**
     * View build details and logs
     */
    #[Route('/builds/{buildId}', name: 'app_build_detail')]
    public function buildDetail(string $buildId): Response
    {
        $build = $this->connection->fetchAssociative(
            'SELECT cb.*, gb.branch_name, gb.pr_url, gb.pr_number
             FROM maestro.ci_builds cb
             JOIN maestro.git_branches gb ON cb.branch_id = gb.id
             WHERE cb.id = :buildId',
            ['buildId' => $buildId]
        );

        if (!$build) {
            throw $this->createNotFoundException('Build introuvable');
        }

        // Decode JSON
        if ($build['test_results']) {
            $build['test_results'] = json_decode($build['test_results'], true);
        }

        // Get branch and project info
        $gitBranch = $this->connection->fetchAssociative(
            'SELECT gb.*, p.name as project_name, p.slug as project_slug
             FROM maestro.git_branches gb
             JOIN maestro.projects p ON gb.project_id = p.id
             WHERE gb.id = :branchId',
            ['branchId' => $build['branch_id']]
        );

        return $this->render('ci/build_detail.html.twig', [
            'build' => $build,
            'gitBranch' => $gitBranch,
        ]);
    }
}
