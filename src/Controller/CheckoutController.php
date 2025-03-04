<?php
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
            $data = json_decode($request->getContent(), true);
            
            // Gestion du client
            $clientId = null;
            if (!empty($data['user']['id'])) {
                // Vérifier que le client existe
                $client = $connection->fetchAssociative('SELECT * FROM client WHERE id = ?', [$data['user']['id']]);
                if (!$client) {
                    return new JsonResponse(['success' => false, 'error' => 'Client non trouvé'], 400);
                }
                $clientId = $client['id'];
            } else {
                // Création d'un client temporaire
                if (empty($data['user']['nom']) || empty($data['user']['prenom'])) {
                    return new JsonResponse(['success' => false, 'error' => 'Nom et prénom requis'], 400);
                }
                
                $connection->insert('client', [
                    'nom' => $data['user']['nom'],
                    'prenom' => $data['user']['prenom'],
                    'mdp' => bin2hex(random_bytes(8)) // Génère un mot de passe aléatoire
                ]);
                $clientId = $connection->lastInsertId();
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
            $orderLineNumber = 1;
            
            // Traitement de chaque article du panier
            foreach ($cartItems as $item) {
                // Si l'item est un menu (présence de la clé "menuId")
                // Si l'item est un menu (présence de la clé "menuId")
                if (isset($item['menuId'])) {
                    $menuId = $item['menuId'];
                    // Format attendu : "menu-{produitId}-{timestamp}"
                    $parts = explode('-', $menuId);
                    if (count($parts) < 3) {
                        return new JsonResponse(['success' => false, 'error' => 'Format de menuId invalide'], 400);
                    }
                    $menuProduitId = $parts[1];
                    $quantity = $item['quantity'];
                    
                    // Récupérer les familles associées au menu (le produit qui est un menu)
                    $familles = $connection->fetchAllAssociative(
                        'SELECT id, nb_min, nb_max, libelle FROM famille WHERE produit_id = ?',
                        [$menuProduitId]
                    );
                    if (empty($familles)) {
                        return new JsonResponse(['success' => false, 'error' => "Aucune famille trouvée pour le menu produit ID $menuProduitId"], 400);
                    }
                    $menuTotalComponents = 0;
                    
                    // Pour chaque famille, récupérer l'avoir par défaut via la table composer
                    foreach ($familles as $famille) {
                        $avoir = $connection->fetchAssociative(
                            'SELECT avoir.id, avoir.taille_id, avoir.prix, avoir.produit_id
                             FROM avoir 
                             INNER JOIN composer ON composer.avoir_id = avoir.id 
                             WHERE composer.famille_id = ? 
                             LIMIT 1',
                            [$famille['id']]
                        );
                        if (!$avoir) {
                            return new JsonResponse(['success' => false, 'error' => "Aucun avoir trouvé pour la famille ID " . $famille['id']], 400);
                        }
                        $menuTotalComponents += $avoir['prix'];
                        
                        // Ajout d'une ligne de commande pour ce composant du menu
                        $orderLines[] = [
                            'produit_id'   => $avoir['produit_id'], // Utilisation de l'ID du produit composant
                            'taille_id'    => $avoir['taille_id'],
                            'quantite'     => $quantity,
                            'prix'         => $avoir['prix'],
                            'numero_ordre' => $orderLineNumber++,
                            'numero_menu'  => null,
                        ];
                    }
                    
                    // Récupérer la valeur de réduction du produit menu via son champ "valeur"
                    $menuProduit = $connection->fetchAssociative(
                        'SELECT valeur FROM produit WHERE id = ?',
                        [$menuProduitId]
                    );
                    if (!$menuProduit) {
                        return new JsonResponse(['success' => false, 'error' => "Produit menu non trouvé pour l'ID $menuProduitId"], 400);
                    }
                    $reduction = $menuProduit['valeur'];
                    
                    // Mise à jour du prix total pour ce menu :
                    // On applique la réduction sur le prix total des composants
                    $totalPrice += ($menuTotalComponents - $reduction) * $quantity;
                    
                    // Génération d'un numéro aléatoire pour identifier le groupe de menu
                    $numeroMenu = random_int(100000000, 999999999);
                    
                    // On attribue à la ligne de réduction le même numéro d'ordre que le dernier composant inséré
                    $discountOrderNumber = $orderLineNumber - 1;
                    
                    // Insertion de la ligne de réduction dans la commande
                    $orderLines[] = [
                         'produit_id'   => $menuProduitId,  // ID du menu
                         'taille_id'    => null,
                         'quantite'     => $quantity,
                         'prix'         => -$reduction,  // Valeur négative pour la réduction
                         'numero_ordre' => $discountOrderNumber,
                         'numero_menu'  => $numeroMenu,
                    ];
                } else {
                
                    // Traitement d'un produit normal
                    $produitId   = $item['produitId'];
                    $tailleUnite = $item['taille']; // ex : "unique", "25cl", "500g", etc.
                    $quantity    = $item['quantity'];
                    
                    // Récupérer la taille liée au produit (on prend par défaut la première taille disponible)
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
                    
                    // Récupérer le prix pour la taille donnée
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
                        'numero_ordre' => $orderLineNumber++,
                    ];
                }
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
            
            // Générer le numéro de commande avec 3 chiffres, ex : 001, 002, ...
            $orderNumber = str_pad($commandeId, 3, '0', STR_PAD_LEFT);
            
            // Mise à jour du numéro de commande dans la table `commande`
            $connection->update('commande', [
                'numero_commande' => $orderNumber,
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
                    'numero_menu'  => $line['numero_menu'] ?? null,
                ]);
            }
            
            // Stocker l'ID de la commande dans la session pour l'utiliser dans d'autres contrôleurs
            $request->getSession()->set('commande_id', $commandeId);
            
            return new JsonResponse(['success' => true, 'orderNumber' => $orderNumber]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
