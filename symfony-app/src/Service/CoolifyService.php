<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CoolifyService
{
    private string $coolifyUrl;
    private string $coolifyToken;
    private string $appDomain;
    private ?string $serverUuid = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        string $coolifyUrl = 'https://coolify.maestro.ara-solutions.cloud',
        string $coolifyToken = '',
        string $appDomain = 'maestro.ara-solutions.cloud'
    ) {
        $this->coolifyUrl = rtrim($coolifyUrl, '/');
        $this->coolifyToken = $coolifyToken;
        $this->appDomain = $appDomain;
    }

    /**
     * Deploy a branch to an environment
     */
    public function deployBranch(string $projectUuid, string $branch, string $environment = 'staging'): ?array
    {
        try {
            $this->logger->info('Deploying to Coolify', [
                'project' => $projectUuid,
                'branch' => $branch,
                'environment' => $environment
            ]);

            $response = $this->httpClient->request('POST', $this->coolifyUrl . '/api/v1/deploy', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->coolifyToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'project_uuid' => $projectUuid,
                    'branch' => $branch,
                    'environment' => $environment,
                    'force' => false,
                ],
            ]);

            if ($response->getStatusCode() === 201 || $response->getStatusCode() === 200) {
                $data = $response->toArray();

                $this->logger->info('Deployment started', [
                    'deployment_id' => $data['deployment_id'] ?? null
                ]);

                return [
                    'success' => true,
                    'deployment_id' => $data['deployment_id'] ?? null,
                    'status' => $data['status'] ?? 'PENDING',
                    'url' => $data['url'] ?? null,
                ];
            }

            return null;

        } catch (\Exception $e) {
            $this->logger->error('Failed to deploy', [
                'error' => $e->getMessage(),
                'project' => $projectUuid
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get deployment status
     */
    public function getDeploymentStatus(string $deploymentId): ?array
    {
        try {
            $response = $this->httpClient->request('GET', $this->coolifyUrl . '/api/v1/deployments/' . $deploymentId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->coolifyToken,
                ],
            ]);

            if ($response->getStatusCode() === 200) {
                return $response->toArray();
            }

            return null;

        } catch (\Exception $e) {
            $this->logger->error('Failed to get deployment status', [
                'error' => $e->getMessage(),
                'deployment_id' => $deploymentId
            ]);
            return null;
        }
    }

    /**
     * List deployments for a project
     */
    public function listDeployments(string $projectUuid, int $limit = 20): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->coolifyUrl . '/api/v1/projects/' . $projectUuid . '/deployments', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->coolifyToken,
                ],
                'query' => [
                    'limit' => $limit,
                ],
            ]);

            if ($response->getStatusCode() === 200) {
                return $response->toArray();
            }

            return [];

        } catch (\Exception $e) {
            $this->logger->error('Failed to list deployments', [
                'error' => $e->getMessage(),
                'project' => $projectUuid
            ]);
            return [];
        }
    }

    /**
     * Rollback to previous deployment
     */
    public function rollback(string $deploymentId): bool
    {
        try {
            $this->logger->info('Rolling back deployment', [
                'deployment_id' => $deploymentId
            ]);

            $response = $this->httpClient->request('POST', $this->coolifyUrl . '/api/v1/deployments/' . $deploymentId . '/rollback', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->coolifyToken,
                    'Content-Type' => 'application/json',
                ],
            ]);

            if ($response->getStatusCode() === 200) {
                $this->logger->info('Rollback successful', [
                    'deployment_id' => $deploymentId
                ]);
                return true;
            }

            return false;

        } catch (\Exception $e) {
            $this->logger->error('Failed to rollback', [
                'error' => $e->getMessage(),
                'deployment_id' => $deploymentId
            ]);
            return false;
        }
    }

    /**
     * Stop a running deployment
     */
    public function stopDeployment(string $deploymentId): bool
    {
        try {
            $response = $this->httpClient->request('POST', $this->coolifyUrl . '/api/v1/deployments/' . $deploymentId . '/stop', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->coolifyToken,
                ],
            ]);

            return $response->getStatusCode() === 200;

        } catch (\Exception $e) {
            $this->logger->error('Failed to stop deployment', [
                'error' => $e->getMessage(),
                'deployment_id' => $deploymentId
            ]);
            return false;
        }
    }

    /**
     * Get deployment logs
     */
    public function getDeploymentLogs(string $deploymentId): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $this->coolifyUrl . '/api/v1/deployments/' . $deploymentId . '/logs', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->coolifyToken,
                ],
            ]);

            if ($response->getStatusCode() === 200) {
                $data = $response->toArray();
                return $data['logs'] ?? null;
            }

            return null;

        } catch (\Exception $e) {
            $this->logger->error('Failed to get deployment logs', [
                'error' => $e->getMessage(),
                'deployment_id' => $deploymentId
            ]);
            return null;
        }
    }

    /**
     * Test connection to Coolify API
     */
    public function testConnection(): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->coolifyUrl . '/api/v1/health', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->coolifyToken,
                ],
            ]);

            if ($response->getStatusCode() === 200) {
                return [
                    'success' => true,
                    'message' => 'Connexion réussie à Coolify',
                ];
            }

            return [
                'success' => false,
                'message' => 'Impossible de se connecter à Coolify',
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Setup complete Coolify environment for a project (AUTOMATIC)
     * Creates project + applications in Production environment
     */
    public function setupProject(string $projectSlug, string $projectName, string $giteaRepoUrl): array
    {
        try {
            $this->logger->info('Setting up Coolify for project', [
                'project' => $projectSlug
            ]);

            // Step 1: Create project in Coolify
            $projectResult = $this->createCoolifyProject($projectName, $projectSlug);
            if (!$projectResult['success']) {
                throw new \Exception('Failed to create Coolify project: ' . $projectResult['error']);
            }

            $projectUuid = $projectResult['uuid'];

            // Add Gitea token to repository URL for authentication
            $giteaToken = $_ENV['GITEA_API_TOKEN'] ?? '';
            $giteaUser = 'maestro';

            // Inject token into URL: https://user:token@git.domain.com/repo
            $authenticatedUrl = $giteaRepoUrl;
            if ($giteaToken && preg_match('#^(https?://)(.+)$#', $giteaRepoUrl, $matches)) {
                $authenticatedUrl = $matches[1] . $giteaUser . ':' . $giteaToken . '@' . $matches[2];
            }

            // Step 2: Create Staging application
            $stagingDomain = 'staging-' . $projectSlug . '.' . $this->appDomain;
            $stagingResult = $this->createApplication(
                $projectUuid,
                $projectSlug . '-staging',
                $authenticatedUrl,
                'main',
                $stagingDomain,
                'staging'
            );

            if (!$stagingResult['success']) {
                throw new \Exception('Failed to create staging app: ' . $stagingResult['error']);
            }

            // Step 3: Create Production application
            $productionDomain = $projectSlug . '.' . $this->appDomain;
            $productionResult = $this->createApplication(
                $projectUuid,
                $projectSlug . '-production',
                $authenticatedUrl,
                'main',
                $productionDomain,
                'production'
            );

            if (!$productionResult['success']) {
                throw new \Exception('Failed to create production app: ' . $productionResult['error']);
            }

            $this->logger->info('Coolify setup successful', [
                'project_uuid' => $projectUuid,
                'staging_uuid' => $stagingResult['uuid'],
                'production_uuid' => $productionResult['uuid']
            ]);

            return [
                'success' => true,
                'coolify_project_uuid' => $projectUuid,
                'coolify_staging_uuid' => $stagingResult['uuid'],
                'coolify_staging_url' => 'https://' . $stagingDomain,
                'coolify_production_uuid' => $productionResult['uuid'],
                'coolify_production_url' => 'https://' . $productionDomain,
            ];

        } catch (\Exception $e) {
            $this->logger->error('Coolify setup failed', [
                'error' => $e->getMessage(),
                'project' => $projectSlug
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get the default server UUID from Coolify
     */
    private function getServerUuid(): ?string
    {
        // Cache the server UUID to avoid multiple API calls
        if ($this->serverUuid !== null) {
            return $this->serverUuid;
        }

        try {
            $response = $this->httpClient->request('GET', $this->coolifyUrl . '/api/v1/servers', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->coolifyToken,
                    'Accept' => 'application/json',
                ],
            ]);

            if ($response->getStatusCode() === 200) {
                $data = $response->toArray();
                if (count($data) > 0) {
                    $this->serverUuid = $data[0]['uuid'];
                    $this->logger->info('Got server UUID', ['server_uuid' => $this->serverUuid]);
                    return $this->serverUuid;
                }
            }

            return null;

        } catch (\Exception $e) {
            $this->logger->error('Failed to get server UUID', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Create a project in Coolify
     */
    private function createCoolifyProject(string $name, string $description): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->coolifyUrl . '/api/v1/projects', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->coolifyToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'name' => $name,
                    'description' => 'MAESTRO - ' . $description,
                ],
            ]);

            if ($response->getStatusCode() === 201) {
                $data = $response->toArray();
                return [
                    'success' => true,
                    'uuid' => $data['uuid'] ?? $data['id'],
                ];
            }

            return [
                'success' => false,
                'error' => 'Invalid response from Coolify',
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create an application in Coolify (staging or production)
     * NOTE: Coolify API v4 structure - uses /api/v1/applications/public
     */
    private function createApplication(
        string $projectUuid,
        string $appName,
        string $gitRepository,
        string $gitBranch,
        string $domain,
        string $environment
    ): array {
        try {
            // Get server UUID
            $serverUuid = $this->getServerUuid();
            if (!$serverUuid) {
                throw new \Exception('Failed to get server UUID');
            }

            $this->logger->info('Creating Coolify application', [
                'name' => $appName,
                'domain' => $domain,
                'project_uuid' => $projectUuid,
                'server_uuid' => $serverUuid
            ]);

            // Coolify API v4 structure: /api/v1/applications/public
            $response = $this->httpClient->request('POST', $this->coolifyUrl . '/api/v1/applications/public', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->coolifyToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'project_uuid' => $projectUuid,
                    'server_uuid' => $serverUuid,
                    'environment_name' => 'production',
                    'destination_uuid' => 0,
                    'type' => 'public',
                    'name' => $appName,
                    'description' => 'MAESTRO - ' . $appName . ' - Domain: ' . $domain,
                    'git_repository' => $gitRepository,
                    'git_branch' => $gitBranch,
                    'build_pack' => 'nixpacks',
                    'ports_exposes' => '80',
                    'instant_deploy' => false,
                    // Note: domains field removed - Coolify validates domains against wildcard_domain
                    // Domain should be configured manually in Coolify UI or after DNS is properly set
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $this->logger->info('Coolify API response', [
                'status_code' => $statusCode,
                'app' => $appName
            ]);

            if ($statusCode === 201 || $statusCode === 200) {
                $data = $response->toArray();

                $this->logger->info('Coolify response data', [
                    'data' => $data,
                    'app' => $appName
                ]);

                return [
                    'success' => true,
                    'uuid' => $data['uuid'] ?? $data['id'] ?? null,
                ];
            }

            $responseBody = $response->getContent(false);
            $this->logger->error('Unexpected Coolify response', [
                'status_code' => $statusCode,
                'body' => $responseBody,
                'app' => $appName
            ]);

            return [
                'success' => false,
                'error' => 'Invalid response from Coolify (status: ' . $statusCode . ')',
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to create Coolify application', [
                'error' => $e->getMessage(),
                'app' => $appName,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
