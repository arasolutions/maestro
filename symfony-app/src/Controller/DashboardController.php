<?php

namespace App\Controller;

use App\Service\StatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly StatsService $statsService
    ) {
    }

    /**
     * Ancien route dashboard - redirige vers la sélection de projet
     * @deprecated Utiliser app_project_dashboard à la place
     */
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(Request $request): Response
    {
        // Si un projet est sélectionné en session, rediriger vers son dashboard
        $currentProjectSlug = $request->getSession()->get('current_project_slug');
        if ($currentProjectSlug) {
            return $this->redirectToRoute('app_project_dashboard', ['slug' => $currentProjectSlug]);
        }

        // Sinon, rediriger vers la page de sélection
        return $this->redirectToRoute('app_home');
    }
}
