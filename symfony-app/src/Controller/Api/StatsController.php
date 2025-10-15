<?php

namespace App\Controller\Api;

use App\Service\StatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class StatsController extends AbstractController
{
    public function __construct(
        private readonly StatsService $statsService
    ) {
    }

    #[Route('/stats', name: 'api_stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        $stats = $this->statsService->getDashboardStats();

        return $this->json($stats);
    }
}
