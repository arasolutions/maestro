<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class N8nService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $n8nWebhookUrl
    ) {
    }

    /**
     * Trigger n8n analyzer agent workflow
     *
     * @param string $request The request text to analyze
     * @param array $metadata Additional metadata (request_id, project_id, etc.)
     * @return array Response from n8n webhook
     * @throws \Exception If the webhook call fails
     */
    public function triggerOrchestration(string $request, array $metadata = []): array
    {
        try {
            $payload = [
                'request' => $request,
                'timestamp' => (new \DateTime())->format('c'),
                ...$metadata
            ];

            $webhookUrl = $this->n8nWebhookUrl . '/analyze';

            $this->logger->info('Triggering n8n analyzer agent', [
                'url' => $webhookUrl,
                'request' => substr($request, 0, 100) // Log first 100 chars
            ]);

            $response = $this->httpClient->request('POST', $webhookUrl, [
                'json' => $payload,
                'timeout' => 30,
                'headers' => [
                    'Content-Type' => 'application/json',
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->toArray();

            $this->logger->info('n8n analyzer agent triggered successfully', [
                'status_code' => $statusCode,
                'has_response' => !empty($content)
            ]);

            return $content;

        } catch (\Exception $e) {
            $this->logger->error('Failed to trigger n8n analyzer agent', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new \Exception('Failed to trigger n8n analyzer agent: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get webhook execution status
     *
     * @param string $executionId The n8n execution ID
     * @return array Execution status information
     */
    public function getExecutionStatus(string $executionId): array
    {
        try {
            $this->logger->info('Fetching n8n execution status', ['execution_id' => $executionId]);

            $response = $this->httpClient->request('GET', $this->n8nWebhookUrl . '/execution/' . $executionId, [
                'timeout' => 10
            ]);

            return $response->toArray();

        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch execution status', [
                'execution_id' => $executionId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Health check for n8n service
     *
     * @return bool True if n8n is reachable
     */
    public function healthCheck(): bool
    {
        try {
            $response = $this->httpClient->request('GET', $this->n8nWebhookUrl . '/healthz', [
                'timeout' => 5
            ]);

            return $response->getStatusCode() === 200;

        } catch (\Exception $e) {
            $this->logger->warning('n8n health check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
