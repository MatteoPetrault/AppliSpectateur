<?php
// src/Controller/CommandeController.php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;


class CommandeController extends AbstractController
{
    /**
     * @Route("/commande", name="commande_index")
     */
    public function index(Request $request): Response
    {
        // Récupérer l'ID de la commande depuis la session
        $commandeId = $request->getSession()->get('commande_id');

        if (!$commandeId) {
            // Gérer le cas où l'ID de la commande n'existe pas
            return $this->redirectToRoute('home');
        }

        // Récupérer le statut de la commande (adapte cette partie en fonction de ta logique)
        $statut = 'Enregistrée'; // Exemple de statut

        return $this->render('commande/index.html.twig', [
            'statut' => $statut,
            'commande' => ['id' => $commandeId],
        ]);
    }


    /**
     * @Route("/commande/statut/{id}", name="commande_statut", methods={"GET"})
     */
    public function statut($id, Connection $connection): JsonResponse
    {
        // Récupérer le libellé du statut de la commande via son ID
        $statut = $connection->fetchOne(
            'SELECT s.libelle FROM commande c JOIN statut s ON c.statut_id = s.id WHERE c.id = ?',
            [$id]
        );

        if (!$statut) {
            return new JsonResponse(['success' => false, 'error' => 'Commande non trouvée'], 404);
        }

        // Retourner le libellé du statut sous forme de réponse JSON
        return new JsonResponse(['success' => true, 'statut' => $statut]);
    }



}