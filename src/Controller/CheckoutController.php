<?php
// src/Controller/CheckoutController.php
// src/Controller/CheckoutController.php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\Annotation\Route;

class CheckoutController extends AbstractController
{
    /**
     * @Route("/checkout", name="checkout", methods={"POST"})
     */
    public function index(Request $request, Connection $connection): JsonResponse
    {
        try {
            // Décodage des données JSON envoyées depuis le front-end
            $data = json_decode($request->getContent(), true);
            if (!$data) {
                return new JsonResponse(['success' => false, 'error' => 'JSON invalide'], 400);
            }

            // Gestion du client (si connecté, récupérer les informations; sinon, demander nom/prénom)
            $clientId = null;
            $prenom = $nom = $email = null;

            // Si l'utilisateur est connecté, on récupère ses informations
            if (isset($data['user']['id']) && !empty($data['user']['id'])) {
                $clientId = $data['user']['id'];
                $prenom = $data['user']['prenom'];
                $nom = $data['user']['nom'];
                $email = $data['user']['email'] ?? null;  // email est disponible si connecté
            } else {
                // Si non connecté, on demande nom et prénom
                $prenom = $data['user']['prenom'] ?? null;
                $nom  = $data['user']['nom'] ?? null;
                
                // Si nom et prénom manquants, retour erreur
                if (!$prenom || !$nom) {
                    return new JsonResponse(['success' => false, 'error' => 'Informations client manquantes'], 400);
                }

                // Création d'un client en base de données si non connecté
                $connection->insert('client', [
                    'nom'    => $nom,
                    'prenom' => $prenom,
                ]);
                $clientId = $connection->lastInsertId();  // Récupérer l'ID du client créé
            }

            // Vérification du panier
            $cartItems = $data['cart'] ?? [];
            if (empty($cartItems)) {
                return new JsonResponse(['success' => false, 'error' => 'Le panier est vide'], 400);
            }

            $totalPrice = 0;
            $orderLines = [];
            $orderDate   = new \DateTime();
            $orderTime   = new \DateTime();

            // Traitement de chaque article du panier
            foreach ($cartItems as $index => $item) {
                $produitId   = $item['produitId'];
                $tailleUnite = $item['taille']; // ex : "unique", "25cl", "500g", etc.
                $quantity    = $item['quantity'];

                // Vérifier quelle taille est liée au produit avant de forcer "unique"
                $tailleId = $connection->fetchOne(
                    'SELECT taille_id FROM avoir WHERE produit_id = ? LIMIT 1',
                    [$produitId]
                );

                if (!$tailleId) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => "Aucune taille trouvée pour le produit ID $produitId"
                    ], 400);
                }

                // Vérifier le prix avec la bonne taille_id
                $prix = $connection->fetchOne(
                    'SELECT prix FROM avoir WHERE produit_id = ? AND taille_id = ?',
                    [$produitId, $tailleId]
                );

                if (!$prix) {
                    return new JsonResponse([ 
                        'success' => false, 
                        'error' => "Prix non trouvé pour le produit ID $produitId et la taille ID $tailleId"
                    ], 400);
                }

                $linePrice = $prix * $quantity;
                $totalPrice += $linePrice;

                $orderLines[] = [
                    'produit_id'   => $produitId,
                    'taille_id'    => $tailleId,
                    'quantite'     => $quantity,
                    'prix'         => $prix,
                    'numero_ordre' => $index + 1,
                ];
            }

            // Insertion de la commande dans la table `commande`
            $statutId = 1; // Statut par défaut ("en attente")
            $connection->insert('commande', [
                'client_id'       => $clientId,
                'statut_id'       => $statutId,
                'numero_commande' => '',  // Placeholder pour le numéro de commande
                'heure'           => $orderTime->format('Y-m-d H:i:s'),
                'date'            => $orderDate->format('Y-m-d'),
                'prix_total'      => $totalPrice,
            ]);

            // Récupérer l'ID de la commande insérée
            $commandeId = $connection->lastInsertId();

            // Générer le numéro de commande avec 3 chiffres, ex: 001, 002, ...
            $orderNumber = str_pad($commandeId, 3, '0', STR_PAD_LEFT);

            // Mise à jour du numéro de commande dans la table `commande`
            $connection->update('commande', [
                'numero_commande' => $orderNumber,  // Mise à jour avec le numéro formaté
            ], ['id' => $commandeId]);

            // Insertion de chaque ligne de commande dans la table `ligne_commande`
            foreach ($orderLines as $line) {
                $connection->insert('ligne_commande', [
                    'produit_id'   => $line['produit_id'],
                    'taille_id'    => $line['taille_id'],
                    'commande_id'  => $commandeId,
                    'numero_ordre' => $line['numero_ordre'],
                    'quantite'     => $line['quantite'],
                    'prix'         => $line['prix'],
                ]);
            }

            return new JsonResponse(['success' => true, 'orderNumber' => $orderNumber]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
