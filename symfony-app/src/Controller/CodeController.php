<?php

namespace App\Controller;

use App\Entity\GeneratedCode;
use App\Service\N8nService;
use App\Service\GiteaService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Uid\Uuid;

class CodeController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly N8nService $n8nService,
        private readonly GiteaService $giteaService,
        private readonly SluggerInterface $slugger,
        private readonly LoggerInterface $logger
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

        // Check if code has been committed to Git
        $gitBranch = null;
        if ($generatedCode) {
            $gitBranch = $this->connection->fetchAssociative(
                'SELECT * FROM maestro.git_branches WHERE user_story_id = :userStoryId ORDER BY created_at DESC LIMIT 1',
                ['userStoryId' => $userStoryRecordId]
            );
        }

        return $this->render('code/view.html.twig', [
            'story' => $story,
            'analysis' => $analysis,
            'analysisId' => $analysisId,
            'storyId' => $storyId,
            'project' => $project,
            'generatedCode' => $generatedCode,
            'hasCode' => $generatedCode !== null,
            'gitBranch' => $gitBranch,
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
            $response = $this->n8nService->callWebhook('/dev-generate', [
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

    /**
     * Commit generated code to Gitea and create Pull Request
     */
    #[Route('/user-story/{analysisId}/{storyId}/git-commit', name: 'app_user_story_git_commit', methods: ['POST'])]
    public function gitCommit(string $analysisId, string $storyId, Request $request): Response
    {
        // CSRF validation
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('git_commit_' . $storyId, $token)) {
            $this->addFlash('error', 'Token CSRF invalide');
            return $this->redirectToRoute('app_user_story_code', [
                'analysisId' => $analysisId,
                'storyId' => $storyId
            ]);
        }

        try {
            // Get analysis and project
            $analysis = $this->connection->fetchAssociative(
                'SELECT * FROM maestro.analyses WHERE id = :id',
                ['id' => $analysisId]
            );

            $project = $this->connection->fetchAssociative(
                'SELECT * FROM maestro.projects WHERE id = :id',
                ['id' => $analysis['project_id']]
            );

            // Verify Gitea repo exists
            if (!$project['gitea_repo_id']) {
                $this->addFlash('error', 'Le projet n\'a pas de dépôt Gitea configuré');
                return $this->redirectToRoute('app_user_story_code', [
                    'analysisId' => $analysisId,
                    'storyId' => $storyId
                ]);
            }

            // Get user story record
            $userStoriesRecord = $this->connection->fetchAssociative(
                'SELECT id, stories FROM maestro.user_stories WHERE analysis_id = :analysisId',
                ['analysisId' => $analysisId]
            );

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

            // Get generated code
            $generatedCode = $this->entityManager
                ->getRepository(GeneratedCode::class)
                ->findOneBy(['userStoryId' => Uuid::fromString($userStoriesRecord['id'])]);

            if (!$generatedCode) {
                $this->addFlash('error', 'Aucun code généré trouvé');
                return $this->redirectToRoute('app_user_story_code', [
                    'analysisId' => $analysisId,
                    'storyId' => $storyId
                ]);
            }

            // Create branch name: feature/US-{id}-{slug}
            $titleSlug = strtolower($this->slugger->slug($story['title'])->toString());
            $branchName = sprintf('feature/US-%s-%s', substr($storyId, 0, 8), $titleSlug);

            $this->logger->info('Creating Git branch', [
                'project' => $project['slug'],
                'branch' => $branchName,
                'story_id' => $storyId
            ]);

            // Create branch in Gitea
            $branchCreated = $this->giteaService->createBranch(
                $project['slug'],
                $branchName,
                'main'
            );

            if (!$branchCreated) {
                throw new \Exception('Impossible de créer la branche Git');
            }

            // Commit each file
            $commitMessage = sprintf("feat: %s\n\nUser Story: %s\n\nEn tant que: %s\nJe veux: %s\nAfin de: %s",
                $story['title'],
                $storyId,
                $story['as_a'] ?? '',
                $story['i_want'] ?? '',
                $story['so_that'] ?? ''
            );

            $filesCommitted = 0;
            foreach ($generatedCode->getCodeFiles() as $file) {
                $success = $this->giteaService->createOrUpdateFile(
                    $project['slug'],
                    $file['path'],
                    $file['content'],
                    $commitMessage,
                    $branchName
                );

                if ($success) {
                    $filesCommitted++;
                    $this->logger->info('File committed', ['file' => $file['path']]);
                }
            }

            if ($filesCommitted === 0) {
                throw new \Exception('Aucun fichier n\'a pu être commité');
            }

            // Create Pull Request
            $prTitle = sprintf('[US-%s] %s', substr($storyId, 0, 8), $story['title']);
            $prDescription = sprintf(
                "## User Story\n\n**En tant que:** %s\n**Je veux:** %s\n**Afin de:** %s\n\n## Fichiers générés\n\n%s\n\n---\n*Généré automatiquement par MAESTRO Agent DEV*",
                $story['as_a'] ?? '',
                $story['i_want'] ?? '',
                $story['so_that'] ?? '',
                implode("\n", array_map(fn($f) => "- `{$f['path']}`", $generatedCode->getCodeFiles()))
            );

            $prResult = $this->giteaService->createPullRequest(
                $project['slug'],
                $prTitle,
                $prDescription,
                $branchName,
                'main'
            );

            // Save to git_branches table
            $gitBranchId = Uuid::v4();
            $this->connection->insert('maestro.git_branches', [
                'id' => $gitBranchId->toRfc4122(),
                'project_id' => $project['id'],
                'user_story_id' => $userStoriesRecord['id'],
                'branch_name' => $branchName,
                'pr_number' => $prResult['pr_number'] ?? null,
                'pr_url' => $prResult['pr_url'] ?? null,
                'pr_status' => 'OPEN',
                'created_at' => new \DateTime(),
                'updated_at' => new \DateTime(),
            ], [
                'created_at' => 'datetime',
                'updated_at' => 'datetime',
            ]);

            $this->addFlash('success', sprintf(
                'Code commité sur la branche <code>%s</code> ! <a href="%s" target="_blank" class="alert-link">Voir la Pull Request #%d</a>',
                $branchName,
                $prResult['pr_url'] ?? '#',
                $prResult['pr_number'] ?? 0
            ));

            $this->logger->info('Git commit successful', [
                'branch' => $branchName,
                'pr_number' => $prResult['pr_number'] ?? null,
                'files_committed' => $filesCommitted
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Git commit failed', [
                'error' => $e->getMessage(),
                'story_id' => $storyId
            ]);

            $this->addFlash('error', 'Erreur lors du commit Git : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_user_story_code', [
            'analysisId' => $analysisId,
            'storyId' => $storyId
        ]);
    }
}
