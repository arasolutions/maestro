<?php

namespace App\Controller;

use App\Service\N8nService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class TestController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly N8nService $n8nService
    ) {
    }

    /**
     * View generated tests for a user story
     */
    #[Route('/user-story/{analysisId}/{storyId}/tests', name: 'app_user_story_tests')]
    public function viewTests(string $analysisId, string $storyId, Request $request): Response
    {
        // Get user story record ID
        $userStoryRecord = $this->connection->fetchAssociative(
            'SELECT id FROM maestro.user_stories WHERE analysis_id = :analysisId',
            ['analysisId' => $analysisId]
        );

        if (!$userStoryRecord) {
            throw $this->createNotFoundException('User story record introuvable');
        }

        // Get generated code for this user story
        $generatedCode = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.generated_code WHERE user_story_id = :userStoryId ORDER BY created_at DESC LIMIT 1',
            ['userStoryId' => $userStoryRecord['id']]
        );

        // Get test scenarios
        $testScenarios = null;
        if ($userStoryRecord) {
            $testScenarios = $this->connection->fetchAssociative(
                'SELECT * FROM maestro.test_scenarios WHERE user_story_id = :userStoryId ORDER BY created_at DESC LIMIT 1',
                ['userStoryId' => $userStoryRecord['id']]
            );

            if ($testScenarios) {
                // Decode JSONB fields
                $testScenarios['scenarios'] = json_decode($testScenarios['scenarios'], true);
                $testScenarios['test_code'] = json_decode($testScenarios['test_code'], true);
            }
        }

        // Get analysis details
        $analysis = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.analyses WHERE id = :id',
            ['id' => $analysisId]
        );

        // Get project
        $project = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.projects WHERE id = :id',
            ['id' => $analysis['project_id']]
        );

        // Get the specific story from JSONB
        $storiesData = $this->connection->fetchOne(
            'SELECT stories FROM maestro.user_stories WHERE analysis_id = :analysisId',
            ['analysisId' => $analysisId]
        );

        $stories = json_decode($storiesData, true);
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

        return $this->render('test/view.html.twig', [
            'story' => $story,
            'analysis' => $analysis,
            'analysisId' => $analysisId,
            'storyId' => $storyId,
            'project' => $project,
            'generatedCode' => $generatedCode,
            'testScenarios' => $testScenarios,
            'hasTests' => $testScenarios !== null,
        ]);
    }

    /**
     * Generate tests for a user story (triggers Agent TEST)
     */
    #[Route('/user-story/{analysisId}/{storyId}/generate-tests', name: 'app_user_story_generate_tests', methods: ['POST'])]
    public function generateTests(string $analysisId, string $storyId, Request $request): Response
    {
        // CSRF validation
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('generate_tests_' . $storyId, $token)) {
            $this->addFlash('error', 'Token CSRF invalide');
            return $this->redirectToRoute('app_user_story_tests', [
                'analysisId' => $analysisId,
                'storyId' => $storyId
            ]);
        }

        try {
            // Get the user story record
            $userStoryRecord = $this->connection->fetchAssociative(
                'SELECT id, stories FROM maestro.user_stories WHERE analysis_id = :analysisId',
                ['analysisId' => $analysisId]
            );

            $stories = json_decode($userStoryRecord['stories'], true);
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

            // Get the generated code
            $generatedCode = $this->connection->fetchAssociative(
                'SELECT id FROM maestro.generated_code WHERE user_story_id = :userStoryId ORDER BY created_at DESC LIMIT 1',
                ['userStoryId' => $userStoryRecord['id']]
            );

            if (!$generatedCode) {
                $this->addFlash('error', 'Aucun code généré trouvé. Générez d\'abord le code avant de créer les tests.');
                return $this->redirectToRoute('app_user_story_code', [
                    'analysisId' => $analysisId,
                    'storyId' => $storyId
                ]);
            }

            // Call n8n webhook for TEST Agent
            $response = $this->n8nService->callWebhook('/test-generate', [
                'generated_code_id' => $generatedCode['id'],
                'user_story_id' => $userStoryRecord['id'],
                'story' => $story,
            ]);

            $this->addFlash('success', 'Génération des tests lancée ! Les tests seront disponibles dans quelques instants.');

            return $this->redirectToRoute('app_user_story_tests', [
                'analysisId' => $analysisId,
                'storyId' => $storyId
            ]);

        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la génération des tests : ' . $e->getMessage());
            return $this->redirectToRoute('app_user_story_tests', [
                'analysisId' => $analysisId,
                'storyId' => $storyId
            ]);
        }
    }
}
