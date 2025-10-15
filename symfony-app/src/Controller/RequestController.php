<?php

namespace App\Controller;

use App\Service\N8nService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RequestController extends AbstractController
{
    public function __construct(
        private readonly N8nService $n8nService
    ) {
    }

    /**
     * Formulaire de soumission d'une nouvelle requête
     */
    #[Route('/request/new', name: 'app_request_new')]
    public function new(Request $request): Response
    {
        // Vérifier qu'un projet est sélectionné
        $currentProjectSlug = $request->getSession()->get('current_project_slug');
        if (!$currentProjectSlug) {
            $this->addFlash('warning', 'Veuillez d\'abord sélectionner un projet');
            return $this->redirectToRoute('app_home');
        }

        $currentProjectName = $request->getSession()->get('current_project_name');

        if ($request->isMethod('POST')) {
            $title = $request->request->get('title');
            $description = $request->request->get('description');
            $type = $request->request->get('type');
            $priority = $request->request->get('priority');

            // Validation
            if (empty($title) || empty($description)) {
                $this->addFlash('error', 'Le titre et la description sont obligatoires');
                return $this->render('request/new.html.twig', [
                    'project_name' => $currentProjectName,
                    'title' => $title,
                    'description' => $description,
                    'type' => $type,
                    'priority' => $priority,
                ]);
            }

            try {
                // Préparer le payload pour n8n
                $requestText = "Titre: {$title}\n\nDescription: {$description}";

                $metadata = [
                    'project_slug' => $currentProjectSlug,
                    'project_name' => $currentProjectName,
                    'type' => $type,
                    'priority' => $priority,
                    'title' => $title,
                ];

                // Appeler le webhook n8n
                $response = $this->n8nService->triggerOrchestration($requestText, $metadata);

                $this->addFlash('success', 'Votre requête a été soumise avec succès et est en cours d\'analyse par les agents IA');

                // Si n8n retourne un ID d'analyse, rediriger vers les détails
                if (isset($response['analysis_id'])) {
                    return $this->redirectToRoute('app_analysis_detail', ['id' => $response['analysis_id']]);
                }

                // Sinon, rediriger vers le dashboard
                return $this->redirectToRoute('app_project_dashboard', ['slug' => $currentProjectSlug]);

            } catch (\Exception $e) {
                $this->addFlash('error', 'Erreur lors de la soumission: ' . $e->getMessage());
                return $this->render('request/new.html.twig', [
                    'project_name' => $currentProjectName,
                    'title' => $title,
                    'description' => $description,
                    'type' => $type,
                    'priority' => $priority,
                ]);
            }
        }

        return $this->render('request/new.html.twig', [
            'project_name' => $currentProjectName,
        ]);
    }
}
