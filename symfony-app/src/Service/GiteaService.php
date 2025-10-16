<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GiteaService
{
    private string $giteaUrl;
    private string $giteaToken;
    private string $giteaOrgName;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        string $giteaUrl = 'https://git.maestro.ara-solutions.cloud',
        string $giteaToken = '',
        string $giteaOrgName = 'maestro'
    ) {
        $this->giteaUrl = rtrim($giteaUrl, '/');
        $this->giteaToken = $giteaToken;
        $this->giteaOrgName = $giteaOrgName;
    }

    /**
     * Crée un repository dans Gitea pour un projet
     */
    public function createRepository(string $projectName, string $description = ''): ?array
    {
        try {
            $this->logger->info('Creating Gitea repository', ['project' => $projectName]);

            $response = $this->httpClient->request('POST', $this->giteaUrl . '/api/v1/orgs/' . $this->giteaOrgName . '/repos', [
                'headers' => [
                    'Authorization' => 'token ' . $this->giteaToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'name' => $projectName,
                    'description' => $description,
                    'private' => true,
                    'auto_init' => true, // Crée un README.md initial
                    'default_branch' => 'main',
                    'gitignores' => 'Symfony',
                    'license' => 'MIT',
                ],
            ]);

            if ($response->getStatusCode() === 201) {
                $data = $response->toArray();

                $this->logger->info('Gitea repository created', [
                    'repo_id' => $data['id'],
                    'repo_url' => $data['clone_url']
                ]);

                return [
                    'success' => true,
                    'repo_id' => $data['id'],
                    'repo_name' => $data['name'],
                    'repo_url' => $data['html_url'],
                    'clone_url' => $data['clone_url'],
                    'ssh_url' => $data['ssh_url'],
                ];
            }

            return null;

        } catch (\Exception $e) {
            $this->logger->error('Failed to create Gitea repository', [
                'error' => $e->getMessage(),
                'project' => $projectName
            ]);
            return null;
        }
    }

    /**
     * Récupère les informations d'un repository
     */
    public function getRepository(string $repoName): ?array
    {
        try {
            $response = $this->httpClient->request('GET', $this->giteaUrl . '/api/v1/repos/' . $this->giteaOrgName . '/' . $repoName, [
                'headers' => [
                    'Authorization' => 'token ' . $this->giteaToken,
                ],
            ]);

            if ($response->getStatusCode() === 200) {
                return $response->toArray();
            }

            return null;

        } catch (\Exception $e) {
            $this->logger->error('Failed to get Gitea repository', [
                'error' => $e->getMessage(),
                'repo' => $repoName
            ]);
            return null;
        }
    }

    /**
     * Crée une branche dans un repository
     */
    public function createBranch(string $repoName, string $branchName, string $fromBranch = 'main'): bool
    {
        try {
            $this->logger->info('Creating branch in Gitea', [
                'repo' => $repoName,
                'branch' => $branchName,
                'from' => $fromBranch
            ]);

            $response = $this->httpClient->request('POST', $this->giteaUrl . '/api/v1/repos/' . $this->giteaOrgName . '/' . $repoName . '/branches', [
                'headers' => [
                    'Authorization' => 'token ' . $this->giteaToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'new_branch_name' => $branchName,
                    'old_branch_name' => $fromBranch,
                ],
            ]);

            return $response->getStatusCode() === 201;

        } catch (\Exception $e) {
            $this->logger->error('Failed to create branch', [
                'error' => $e->getMessage(),
                'repo' => $repoName,
                'branch' => $branchName
            ]);
            return false;
        }
    }

    /**
     * Crée ou met à jour un fichier dans un repository
     */
    public function createOrUpdateFile(string $repoName, string $filePath, string $content, string $message, string $branch = 'main'): bool
    {
        try {
            // D'abord vérifier si le fichier existe pour récupérer son SHA
            $sha = null;
            try {
                $existingFile = $this->httpClient->request('GET', $this->giteaUrl . '/api/v1/repos/' . $this->giteaOrgName . '/' . $repoName . '/contents/' . $filePath, [
                    'headers' => [
                        'Authorization' => 'token ' . $this->giteaToken,
                    ],
                    'query' => ['ref' => $branch],
                ]);

                if ($existingFile->getStatusCode() === 200) {
                    $fileData = $existingFile->toArray();
                    $sha = $fileData['sha'];
                }
            } catch (\Exception $e) {
                // Fichier n'existe pas, c'est ok
            }

            $payload = [
                'content' => base64_encode($content),
                'message' => $message,
                'branch' => $branch,
            ];

            if ($sha) {
                $payload['sha'] = $sha;
            }

            $response = $this->httpClient->request('POST', $this->giteaUrl . '/api/v1/repos/' . $this->giteaOrgName . '/' . $repoName . '/contents/' . $filePath, [
                'headers' => [
                    'Authorization' => 'token ' . $this->giteaToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            return $response->getStatusCode() === 201 || $response->getStatusCode() === 200;

        } catch (\Exception $e) {
            $this->logger->error('Failed to create/update file', [
                'error' => $e->getMessage(),
                'repo' => $repoName,
                'file' => $filePath
            ]);
            return false;
        }
    }

    /**
     * Crée une Pull Request
     */
    public function createPullRequest(string $repoName, string $title, string $description, string $headBranch, string $baseBranch = 'main'): ?array
    {
        try {
            $this->logger->info('Creating Pull Request', [
                'repo' => $repoName,
                'head' => $headBranch,
                'base' => $baseBranch
            ]);

            $response = $this->httpClient->request('POST', $this->giteaUrl . '/api/v1/repos/' . $this->giteaOrgName . '/' . $repoName . '/pulls', [
                'headers' => [
                    'Authorization' => 'token ' . $this->giteaToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'title' => $title,
                    'body' => $description,
                    'head' => $headBranch,
                    'base' => $baseBranch,
                ],
            ]);

            if ($response->getStatusCode() === 201) {
                $data = $response->toArray();

                return [
                    'success' => true,
                    'pr_number' => $data['number'],
                    'pr_url' => $data['html_url'],
                    'pr_id' => $data['id'],
                ];
            }

            return null;

        } catch (\Exception $e) {
            $this->logger->error('Failed to create Pull Request', [
                'error' => $e->getMessage(),
                'repo' => $repoName
            ]);
            return null;
        }
    }

    /**
     * Met à jour le statut d'une Pull Request
     */
    public function updatePullRequestStatus(string $repoName, int $prNumber, string $status): bool
    {
        try {
            $state = match($status) {
                'open' => 'open',
                'closed' => 'closed',
                default => 'open'
            };

            $response = $this->httpClient->request('PATCH', $this->giteaUrl . '/api/v1/repos/' . $this->giteaOrgName . '/' . $repoName . '/pulls/' . $prNumber, [
                'headers' => [
                    'Authorization' => 'token ' . $this->giteaToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'state' => $state,
                ],
            ]);

            return $response->getStatusCode() === 201;

        } catch (\Exception $e) {
            $this->logger->error('Failed to update PR status', [
                'error' => $e->getMessage(),
                'pr' => $prNumber
            ]);
            return false;
        }
    }

    /**
     * Ajoute un commentaire à une Pull Request
     */
    public function addPullRequestComment(string $repoName, int $prNumber, string $comment): bool
    {
        try {
            $response = $this->httpClient->request('POST', $this->giteaUrl . '/api/v1/repos/' . $this->giteaOrgName . '/' . $repoName . '/issues/' . $prNumber . '/comments', [
                'headers' => [
                    'Authorization' => 'token ' . $this->giteaToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'body' => $comment,
                ],
            ]);

            return $response->getStatusCode() === 201;

        } catch (\Exception $e) {
            $this->logger->error('Failed to add PR comment', [
                'error' => $e->getMessage(),
                'pr' => $prNumber
            ]);
            return false;
        }
    }

    /**
     * Merge une Pull Request
     */
    public function mergePullRequest(string $repoName, int $prNumber): bool
    {
        try {
            $response = $this->httpClient->request('POST', $this->giteaUrl . '/api/v1/repos/' . $this->giteaOrgName . '/' . $repoName . '/pulls/' . $prNumber . '/merge', [
                'headers' => [
                    'Authorization' => 'token ' . $this->giteaToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'Do' => 'merge',
                ],
            ]);

            return $response->getStatusCode() === 200;

        } catch (\Exception $e) {
            $this->logger->error('Failed to merge PR', [
                'error' => $e->getMessage(),
                'pr' => $prNumber
            ]);
            return false;
        }
    }

    /**
     * Crée un webhook pour un repository
     */
    public function createWebhook(string $repoName, string $webhookUrl, string $secret): ?int
    {
        try {
            $this->logger->info('Creating webhook', [
                'repo' => $repoName,
                'url' => $webhookUrl
            ]);

            $response = $this->httpClient->request('POST', $this->giteaUrl . '/api/v1/repos/' . $this->giteaOrgName . '/' . $repoName . '/hooks', [
                'headers' => [
                    'Authorization' => 'token ' . $this->giteaToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'type' => 'gitea',
                    'config' => [
                        'url' => $webhookUrl,
                        'content_type' => 'json',
                        'secret' => $secret,
                    ],
                    'events' => ['push', 'pull_request', 'create', 'delete'],
                    'active' => true,
                ],
            ]);

            if ($response->getStatusCode() === 201) {
                $data = $response->toArray();
                return $data['id'];
            }

            return null;

        } catch (\Exception $e) {
            $this->logger->error('Failed to create webhook', [
                'error' => $e->getMessage(),
                'repo' => $repoName
            ]);
            return null;
        }
    }

    /**
     * Test la connexion à l'API Gitea
     */
    public function testConnection(): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->giteaUrl . '/api/v1/user', [
                'headers' => [
                    'Authorization' => 'token ' . $this->giteaToken,
                ],
            ]);

            if ($response->getStatusCode() === 200) {
                $user = $response->toArray();
                return [
                    'success' => true,
                    'message' => 'Connexion réussie à Gitea',
                    'user' => $user['login'],
                ];
            }

            return [
                'success' => false,
                'message' => 'Impossible de se connecter à Gitea',
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage(),
            ];
        }
    }
}
