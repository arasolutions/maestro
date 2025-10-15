<?php

namespace App\Controller;

use App\Service\StatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly StatsService $statsService
    ) {
    }

    #[Route('/', name: 'app_dashboard')]
    public function index(): Response
    {
        $stats = $this->statsService->getDashboardStats();

        return $this->render('dashboard/index.html.twig', [
            'stats' => $stats,
        ]);
    }
}
