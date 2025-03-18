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
use App\Entity\Statut;

class CommandeController extends AbstractController
{
    /**
     * @Route("/commande", name="commande_index")
     */
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        $commandeId = $request->getSession()->get('commande_id');

        if (!$commandeId) {
            return $this->redirectToRoute('home');
        }
        
        // Récupérer la commande depuis la base de données
        $commande = $entityManager->getRepository(Commande::class)->find($commandeId);
        if (!$commande) {
            return $this->redirectToRoute('home');
        }
        
        // Obtenir le libellé réel du statut (ex: "Enregistrée")
        $statut = $commande->getStatut()->getLibelle();
        
        return $this->render('commande/index.html.twig', [
            'statut'   => $statut,
            'commande' => $commande,
        ]);
    }

    /**
     * @Route("/commande/statut/{id}", name="commande_statut", methods={"GET"})
     */
    public function statut($id, Connection $connection): JsonResponse
    {
        // Récupérer les informations de la commande
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
            'success'         => true,
            'statut'          => $result['libelle'],
            'nom'             => $result['nom'],
            'prenom'          => $result['prenom'],
            'numero_commande' => $result['numero_commande']
        ]);
    }

    #[Route('/commande/{id}/avis', name: 'enregistrer_avis', methods: ['POST'])]
    public function enregistrerAvis(Request $request, EntityManagerInterface $entityManager, int $id): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $commande = $entityManager->getRepository(Commande::class)->find($id);

        if (!$commande) {
            return new JsonResponse(['success' => false, 'message' => 'Commande non trouvée']);
        }

        // Vérifier si un avis a déjà été soumis
        if ($commande->getNoteAvis() !== null && $commande->getCommentaireAvis() !== null) {
            return new JsonResponse(['success' => false, 'message' => 'Un avis a déjà été soumis pour cette commande.']);
        }

        $commande->setNoteAvis($data['note']);
        $commande->setCommentaireAvis($data['commentaire']);
        $entityManager->flush();

        return new JsonResponse(['success' => true, 'message' => 'Avis enregistré avec succès']);
    }

    #[Route('/commande/{id}/annuler', name: 'commande_annuler', methods: ['POST'])]
    public function annulerCommande(EntityManagerInterface $entityManager, int $id): JsonResponse
    {
        $commande = $entityManager->getRepository(Commande::class)->find($id);
    
        if (!$commande) {
            return new JsonResponse(['success' => false, 'message' => 'Commande non trouvée']);
        }
    
        // La commande doit être "Enregistrée" pour pouvoir être annulée
        if ($commande->getStatut()->getLibelle() !== 'Enregistrée') {
            return new JsonResponse(['success' => false, 'message' => 'La commande ne peut pas être annulée']);
        }
    
        // Mettre à jour le statut (statut_id = 5 correspondant à "Annulé")
        $conn = $entityManager->getConnection();
        $result = $conn->executeStatement("UPDATE commande SET statut_id = 5 WHERE id = ?", [$id]);
    
        if ($result === 0) {
            return new JsonResponse(['success' => false, 'message' => 'Erreur lors de la mise à jour du statut']);
        }
    
        return new JsonResponse(['success' => true, 'statut' => 'Annulé']);
    }

    private function getStatutAnnule(EntityManagerInterface $em)
    {
        return $em->getRepository(Statut::class)->findOneBy(['libelle' => 'Annulé']);
    }
}
