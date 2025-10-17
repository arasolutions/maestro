<?php

namespace App\Controller;

use App\Service\CoolifyService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

class DeploymentController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CoolifyService $coolifyService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * List all deployments for a project
     */
    #[Route('/projects/{slug}/deployments', name: 'app_project_deployments')]
    public function listDeployments(string $slug): Response
    {
        $project = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.projects WHERE slug = :slug',
            ['slug' => $slug]
        );

        if (!$project) {
            throw $this->createNotFoundException('Projet introuvable');
        }

        // Get all deployments for this project
        $deployments = $this->connection->fetchAllAssociative(
            'SELECT d.*, gb.branch_name, gb.pr_url, gb.pr_number
             FROM maestro.deployments d
             LEFT JOIN maestro.git_branches gb ON d.branch_id = gb.id
             WHERE d.project_id = :projectId
             ORDER BY d.created_at DESC',
            ['projectId' => $project['id']]
        );

        // Get all git branches for this project
        $gitBranches = $this->connection->fetchAllAssociative(
            'SELECT gb.*, us.stories
             FROM maestro.git_branches gb
             LEFT JOIN maestro.user_stories us ON gb.user_story_id = us.id
             WHERE gb.project_id = :projectId
             ORDER BY gb.created_at DESC',
            ['projectId' => $project['id']]
        );

        return $this->render('deployment/list.html.twig', [
            'project' => $project,
            'deployments' => $deployments,
            'gitBranches' => $gitBranches,
        ]);
    }

    /**
     * Create a new deployment
     */
    #[Route('/projects/{slug}/deploy', name: 'app_project_deploy', methods: ['POST'])]
    public function deploy(string $slug, Request $request): Response
    {
        // CSRF validation
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('deploy_project_' . $slug, $token)) {
            $this->addFlash('error', 'Token CSRF invalide');
            return $this->redirectToRoute('app_project_deployments', ['slug' => $slug]);
        }

        $project = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.projects WHERE slug = :slug',
            ['slug' => $slug]
        );

        if (!$project) {
            throw $this->createNotFoundException('Projet introuvable');
        }

        try {
            $branchId = $request->request->get('branch_id');
            $environment = $request->request->get('environment', 'STAGING');

            // Get branch details
            $gitBranch = $this->connection->fetchAssociative(
                'SELECT * FROM maestro.git_branches WHERE id = :branchId',
                ['branchId' => $branchId]
            );

            if (!$gitBranch) {
                throw new \Exception('Branche Git introuvable');
            }

            // TODO: For now, we'll use n8n webhook instead of direct Coolify API
            // This allows n8n to orchestrate the deployment with Agent DEPLOY

            // Create deployment record
            $deploymentId = Uuid::v4();
            $this->connection->insert('maestro.deployments', [
                'id' => $deploymentId->toRfc4122(),
                'project_id' => $project['id'],
                'branch_id' => $branchId,
                'environment' => $environment,
                'status' => 'PENDING',
                'deployed_by' => 'admin', // TODO: Get current user
                'created_at' => new \DateTime(),
            ], [
                'created_at' => 'datetime',
            ]);

            $this->logger->info('Deployment created', [
                'deployment_id' => $deploymentId->toRfc4122(),
                'project' => $slug,
                'branch' => $gitBranch['branch_name'],
                'environment' => $environment
            ]);

            // TODO: Trigger n8n webhook for Agent DEPLOY
            // $n8nService->callWebhook('/deploy', [
            //     'deployment_id' => $deploymentId->toRfc4122(),
            //     'project_id' => $project['id'],
            //     'branch_id' => $branchId,
            //     'environment' => $environment
            // ]);

            $this->addFlash('success', sprintf(
                'Déploiement vers %s lancé ! Le déploiement sera disponible dans quelques instants.',
                $environment
            ));

        } catch (\Exception $e) {
            $this->logger->error('Deployment failed', [
                'error' => $e->getMessage(),
                'project' => $slug
            ]);

            $this->addFlash('error', 'Erreur lors du déploiement : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_project_deployments', ['slug' => $slug]);
    }

    /**
     * View deployment details
     */
    #[Route('/deployments/{deploymentId}', name: 'app_deployment_detail')]
    public function deploymentDetail(string $deploymentId): Response
    {
        $deployment = $this->connection->fetchAssociative(
            'SELECT d.*, gb.branch_name, gb.pr_url, gb.pr_number, p.name as project_name, p.slug as project_slug
             FROM maestro.deployments d
             LEFT JOIN maestro.git_branches gb ON d.branch_id = gb.id
             LEFT JOIN maestro.projects p ON d.project_id = p.id
             WHERE d.id = :deploymentId',
            ['deploymentId' => $deploymentId]
        );

        if (!$deployment) {
            throw $this->createNotFoundException('Déploiement introuvable');
        }

        // TODO: Get deployment logs from Coolify or n8n
        $logs = null;

        return $this->render('deployment/detail.html.twig', [
            'deployment' => $deployment,
            'logs' => $logs,
        ]);
    }

    /**
     * Rollback a deployment
     */
    #[Route('/deployments/{deploymentId}/rollback', name: 'app_deployment_rollback', methods: ['POST'])]
    public function rollback(string $deploymentId, Request $request): Response
    {
        // CSRF validation
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('rollback_' . $deploymentId, $token)) {
            $this->addFlash('error', 'Token CSRF invalide');
            return $this->redirectToRoute('app_deployment_detail', ['deploymentId' => $deploymentId]);
        }

        try {
            $deployment = $this->connection->fetchAssociative(
                'SELECT * FROM maestro.deployments WHERE id = :id',
                ['id' => $deploymentId]
            );

            if (!$deployment) {
                throw $this->createNotFoundException('Déploiement introuvable');
            }

            // Find previous successful deployment for same environment
            $previousDeployment = $this->connection->fetchAssociative(
                'SELECT * FROM maestro.deployments
                 WHERE project_id = :projectId
                   AND environment = :environment
                   AND status = \'DEPLOYED\'
                   AND created_at < :currentDate
                 ORDER BY created_at DESC
                 LIMIT 1',
                [
                    'projectId' => $deployment['project_id'],
                    'environment' => $deployment['environment'],
                    'currentDate' => $deployment['created_at']
                ]
            );

            if (!$previousDeployment) {
                throw new \Exception('Aucun déploiement précédent trouvé pour rollback');
            }

            // TODO: Trigger rollback via n8n or Coolify
            $this->connection->update('maestro.deployments', [
                'status' => 'ROLLED_BACK'
            ], ['id' => $deploymentId]);

            $this->logger->info('Deployment rolled back', [
                'deployment_id' => $deploymentId,
                'previous_deployment_id' => $previousDeployment['id']
            ]);

            $this->addFlash('success', 'Rollback effectué avec succès');

        } catch (\Exception $e) {
            $this->logger->error('Rollback failed', [
                'error' => $e->getMessage(),
                'deployment_id' => $deploymentId
            ]);

            $this->addFlash('error', 'Erreur lors du rollback : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_deployment_detail', ['deploymentId' => $deploymentId]);
    }
}
