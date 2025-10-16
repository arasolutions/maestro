<?php

namespace App\Controller;

use App\Service\GiteaService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GiteaController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly GiteaService $giteaService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Initialize Gitea repository for a project
     */
    #[Route('/projects/{slug}/gitea/init', name: 'app_gitea_init', methods: ['POST'])]
    public function initializeRepository(string $slug, Request $request): Response
    {
        // Verify CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('gitea_init_' . $slug, $token)) {
            $this->addFlash('error', 'Token CSRF invalide');
            return $this->redirectToRoute('app_project_edit', ['slug' => $slug]);
        }

        // Fetch project
        $project = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.projects WHERE slug = :slug',
            ['slug' => $slug]
        );

        if (!$project) {
            throw $this->createNotFoundException('Projet introuvable');
        }

        // Check if already initialized
        if ($project['gitea_repo_id']) {
            $this->addFlash('warning', 'Un dépôt Gitea existe déjà pour ce projet');
            return $this->redirectToRoute('app_project_edit', ['slug' => $slug]);
        }

        try {
            // Create repository in Gitea
            $this->logger->info('Initializing Gitea repository', [
                'project_slug' => $slug,
                'project_name' => $project['name']
            ]);

            $repoResult = $this->giteaService->createRepository(
                $project['slug'], // Use slug as repo name
                $project['description'] ?? 'Projet MAESTRO - ' . $project['name']
            );

            if (!$repoResult || !$repoResult['success']) {
                throw new \Exception('Échec de la création du dépôt Gitea');
            }

            // Generate webhook secret
            $webhookSecret = bin2hex(random_bytes(16));

            // Update project with Gitea info
            $this->connection->update('maestro.projects', [
                'gitea_repo_id' => $repoResult['repo_id'],
                'gitea_repo_name' => $repoResult['repo_name'],
                'gitea_repo_url' => $repoResult['repo_url'],
                'gitea_webhook_secret' => $webhookSecret
            ], ['id' => $project['id']]);

            // Create webhook pointing to n8n
            $webhookUrl = $_ENV['N8N_WEBHOOK_URL'] ?? 'https://n8n.maestro.ara-solutions.cloud/webhook/gitea';
            $webhookId = $this->giteaService->createWebhook(
                $project['slug'],
                $webhookUrl,
                $webhookSecret
            );

            if ($webhookId) {
                $this->logger->info('Webhook created successfully', [
                    'webhook_id' => $webhookId,
                    'project_slug' => $slug
                ]);
            }

            // Create initial README with project info
            $readme = $this->generateReadme($project);
            $this->giteaService->createOrUpdateFile(
                $project['slug'],
                'README.md',
                $readme,
                '🎯 Initialize MAESTRO project',
                'main'
            );

            $this->addFlash('success', sprintf(
                'Dépôt Gitea créé avec succès ! <a href="%s" target="_blank" class="alert-link">Voir le dépôt</a>',
                $repoResult['repo_url']
            ));

            $this->logger->info('Gitea repository initialized successfully', [
                'project_slug' => $slug,
                'repo_id' => $repoResult['repo_id'],
                'repo_url' => $repoResult['repo_url']
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize Gitea repository', [
                'error' => $e->getMessage(),
                'project_slug' => $slug
            ]);

            $this->addFlash('error', 'Erreur lors de l\'initialisation du dépôt : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_project_edit', ['slug' => $slug]);
    }

    /**
     * Test Gitea connection
     */
    #[Route('/admin/gitea/test', name: 'app_gitea_test', methods: ['GET'])]
    public function testConnection(): Response
    {
        $result = $this->giteaService->testConnection();

        return $this->json($result);
    }

    /**
     * Generate README content for project
     */
    private function generateReadme(array $project): string
    {
        $readme = "# {$project['name']}\n\n";

        if ($project['description']) {
            $readme .= "{$project['description']}\n\n";
        }

        $readme .= "## À propos\n\n";
        $readme .= "Projet généré et géré par **MAESTRO** - Plateforme d'orchestration multi-agents AI.\n\n";

        // Add technical stack if available
        $cadrage = $project['project_cadrage'] ? json_decode($project['project_cadrage'], true) : null;
        if ($cadrage && isset($cadrage['architecture']['stack_technique'])) {
            $readme .= "## Stack Technique\n\n";
            $readme .= $cadrage['architecture']['stack_technique'] . "\n\n";
        }

        $readme .= "## Workflow MAESTRO\n\n";
        $readme .= "Ce projet utilise le workflow automatisé MAESTRO :\n\n";
        $readme .= "1. **Agent PM** - Analyse des besoins\n";
        $readme .= "2. **Agent Cadrage** - Architecture et estimation\n";
        $readme .= "3. **Agent US** - Création des User Stories\n";
        $readme .= "4. **Agent DEV** - Génération de code\n";
        $readme .= "5. **Agent TEST** - Tests automatisés\n";
        $readme .= "6. **Agent DEPLOY** - Déploiement automatisé\n\n";

        $readme .= "## Conventions de branches\n\n";
        $readme .= "- `main` - Branche principale de production\n";
        $readme .= "- `feature/US-XXX-description` - Branches de fonctionnalités\n";
        $readme .= "- `hotfix/description` - Corrections urgentes\n\n";

        $readme .= "---\n\n";
        $readme .= "_Projet créé le " . date('d/m/Y') . " via MAESTRO_\n";

        return $readme;
    }
}
