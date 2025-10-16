<?php

namespace App\Controller;

use App\Entity\GeneratedCode;
use App\Service\N8nService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

class CodeController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly N8nService $n8nService
    ) {
    }

    /**
     * View generated code for a user story
     */
    #[Route('/user-story/{analysisId}/{storyId}/code', name: 'app_user_story_code')]
    public function viewCode(string $analysisId, string $storyId): Response
    {
        // Get the user stories record to find the user_story internal ID
        $userStoriesRecord = $this->connection->fetchAssociative(
            'SELECT id FROM maestro.user_stories WHERE analysis_id = :analysisId',
            ['analysisId' => $analysisId]
        );

        if (!$userStoriesRecord) {
            throw $this->createNotFoundException('User stories introuvables');
        }

        $userStoryRecordId = $userStoriesRecord['id'];

        // Get generated code
        $generatedCode = $this->entityManager
            ->getRepository(GeneratedCode::class)
            ->findOneBy(['userStoryId' => Uuid::fromString($userStoryRecordId)]);

        // Get analysis and story details
        $analysis = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.analyses WHERE id = :id',
            ['id' => $analysisId]
        );

        $project = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.projects WHERE id = :id',
            ['id' => $analysis['project_id']]
        );

        // Get the specific story from JSONB
        $allUserStories = $this->connection->fetchAssociative(
            'SELECT stories FROM maestro.user_stories WHERE analysis_id = :analysisId',
            ['analysisId' => $analysisId]
        );

        $stories = json_decode($allUserStories['stories'], true);
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

        return $this->render('code/view.html.twig', [
            'story' => $story,
            'analysis' => $analysis,
            'analysisId' => $analysisId,
            'storyId' => $storyId,
            'project' => $project,
            'generatedCode' => $generatedCode,
            'hasCode' => $generatedCode !== null,
        ]);
    }

    /**
     * Generate code for a user story (triggers Agent DEV)
     */
    #[Route('/user-story/{analysisId}/{storyId}/generate-code', name: 'app_user_story_generate_code', methods: ['POST'])]
    public function generateCode(string $analysisId, string $storyId, Request $request): Response
    {
        // CSRF validation
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('generate_code_' . $storyId, $token)) {
            $this->addFlash('error', 'Token CSRF invalide');
            return $this->redirectToRoute('app_user_story_code', [
                'analysisId' => $analysisId,
                'storyId' => $storyId
            ]);
        }

        try {
            // Get the specific story from JSONB
            $allUserStories = $this->connection->fetchAssociative(
                'SELECT id, stories FROM maestro.user_stories WHERE analysis_id = :analysisId',
                ['analysisId' => $analysisId]
            );

            $stories = json_decode($allUserStories['stories'], true);
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

            // Call n8n webhook for Dev Agent
            $response = $this->n8nService->callWebhook('/webhook/dev-generate', [
                'analysis_id' => $analysisId,
                'user_story_id' => $allUserStories['id'], // Internal DB ID
                'story' => $story,
            ]);

            $this->addFlash('success', 'Génération du code lancée ! Le code sera disponible dans quelques instants.');

            return $this->redirectToRoute('app_user_story_code', [
                'analysisId' => $analysisId,
                'storyId' => $storyId
            ]);

        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la génération : ' . $e->getMessage());
            return $this->redirectToRoute('app_user_story_code', [
                'analysisId' => $analysisId,
                'storyId' => $storyId
            ]);
        }
    }
}
