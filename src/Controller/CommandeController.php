<?php
// src/Controller/CommandeController.php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Commande; 


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
        // Récupérer les informations complètes de la commande
        $result = $connection->fetchAssociative(
            'SELECT s.libelle, u.nom, u.prenom, c.id AS numero_commande 
            FROM commande c 
            JOIN statut s ON c.statut_id = s.id 
            JOIN client u ON c.client_id = u.id 
            WHERE c.id = ?',
            [$id]
        );

        if (!$result) {
            return new JsonResponse(['success' => false, 'error' => 'Commande non trouvée'], 404);
        }

        return new JsonResponse([
            'success' => true,
            'statut' => $result['libelle'],
            'nom' => $result['nom'],
            'prenom' => $result['prenom'],
            'numero_commande' => $result['numero_commande']
        ]);
    }
    #[Route('/commande/{id}/avis', name: 'enregistrer_avis', methods: ['POST'])]
    public function enregistrerAvis(Request $request, EntityManagerInterface $entityManager, int $id): JsonResponse
    {
        // Récupérer les données JSON envoyées
        $data = json_decode($request->getContent(), true);

        // Récupérer la commande associée
        $commande = $entityManager->getRepository(Commande::class)->find($id);

        if (!$commande) {
            return new JsonResponse(['success' => false, 'message' => 'Commande non trouvée']);
        }

        // Vérifier si un avis a déjà été soumis
        if ($commande->getNoteAvis() !== null && $commande->getCommentaireAvis() !== null) {
            return new JsonResponse(['success' => false, 'message' => 'Un avis a déjà été soumis pour cette commande.']);
        }

        // Mettre à jour les champs note_avis et commentaire_avis
        $commande->setNoteAvis($data['note']);
        $commande->setCommentaireAvis($data['commentaire']);

        // Enregistrer les modifications en base de données
        $entityManager->flush();

        return new JsonResponse(['success' => true, 'message' => 'Avis enregistré avec succès']);
    }


}