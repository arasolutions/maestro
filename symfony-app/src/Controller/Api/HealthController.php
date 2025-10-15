<?php

namespace App\Controller\Api;

use App\Service\N8nService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class HealthController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly N8nService $n8nService
    ) {
    }

    #[Route('/health', name: 'api_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        $status = [
            'status' => 'healthy',
            'timestamp' => (new \DateTime())->format('c'),
            'services' => []
        ];

        // Check database connection
        try {
            $this->connection->fetchOne('SELECT 1');
            $status['services']['database'] = [
                'status' => 'up',
                'type' => 'postgresql'
            ];
        } catch (\Exception $e) {
            $status['status'] = 'degraded';
            $status['services']['database'] = [
                'status' => 'down',
                'error' => $e->getMessage()
            ];
        }

        // Check n8n connection
        $n8nHealthy = $this->n8nService->healthCheck();
        $status['services']['n8n'] = [
            'status' => $n8nHealthy ? 'up' : 'down'
        ];

        if (!$n8nHealthy) {
            $status['status'] = 'degraded';
        }

        $httpStatus = $status['status'] === 'healthy' ? 200 : 503;

        return $this->json($status, $httpStatus);
    }
}
