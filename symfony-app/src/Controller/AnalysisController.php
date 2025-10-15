<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

class AnalysisController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    #[Route('/analysis/{id}', name: 'app_analysis_detail')]
    public function detail(string $id): Response
    {
        // Récupérer l'analyse
        $analysis = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.analyses WHERE id = :id',
            ['id' => $id]
        );

        if (!$analysis) {
            throw $this->createNotFoundException('Analyse introuvable');
        }

        // Récupérer le cadrage associé
        $cadrage = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.cadrages WHERE analysis_id = :analysisId',
            ['analysisId' => $id]
        );

        // Récupérer les user stories associées
        $userStories = $this->connection->fetchAssociative(
            'SELECT * FROM maestro.user_stories WHERE analysis_id = :analysisId',
            ['analysisId' => $id]
        );

        // Décoder les champs JSON
        if ($analysis['agents_needed']) {
            $analysis['agents_needed'] = json_decode($analysis['agents_needed'], true);
        }
        if ($analysis['next_steps']) {
            $analysis['next_steps'] = json_decode($analysis['next_steps'], true);
        }
        if ($analysis['risks']) {
            $analysis['risks'] = json_decode($analysis['risks'], true);
        }
        if ($analysis['full_response']) {
            $analysis['full_response'] = json_decode($analysis['full_response'], true);
        }

        if ($cadrage) {
            $cadrage['perimetre'] = $cadrage['perimetre'] ? json_decode($cadrage['perimetre'], true) : null;
            $cadrage['contraintes'] = $cadrage['contraintes'] ? json_decode($cadrage['contraintes'], true) : null;
            $cadrage['architecture'] = $cadrage['architecture'] ? json_decode($cadrage['architecture'], true) : null;
            $cadrage['swot'] = $cadrage['swot'] ? json_decode($cadrage['swot'], true) : null;
            $cadrage['estimation'] = $cadrage['estimation'] ? json_decode($cadrage['estimation'], true) : null;
        }

        if ($userStories) {
            $userStories['stories'] = $userStories['stories'] ? json_decode($userStories['stories'], true) : null;
            $userStories['acceptance_criteria'] = $userStories['acceptance_criteria'] ? json_decode($userStories['acceptance_criteria'], true) : null;
            $userStories['priority_order'] = $userStories['priority_order'] ? json_decode($userStories['priority_order'], true) : null;
            $userStories['dependencies'] = $userStories['dependencies'] ? json_decode($userStories['dependencies'], true) : null;
        }

        return $this->render('analysis/detail.html.twig', [
            'analysis' => $analysis,
            'cadrage' => $cadrage,
            'userStories' => $userStories,
        ]);
    }
}
