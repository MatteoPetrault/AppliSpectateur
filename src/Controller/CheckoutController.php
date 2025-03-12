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
                $client = $connection->fetchAssociative('SELECT * FROM client WHERE id = ?', [$data['user']['id']]);
                if (!$client) {
                    return new JsonResponse(['success' => false, 'error' => 'Client non trouvé'], 400);
                }
                $clientId = $client['id'];
            } else {
                if (empty($data['user']['nom']) || empty($data['user']['prenom'])) {
                    return new JsonResponse(['success' => false, 'error' => 'Nom et prénom requis'], 400);
                }
                $connection->insert('client', [
                    'nom'    => $data['user']['nom'],
                    'prenom' => $data['user']['prenom'],
                    'mdp'    => bin2hex(random_bytes(8))
                ]);
                $clientId = $connection->lastInsertId();
            }
            
            // Vérification du panier
            $cartItems = $data['cart'] ?? [];
            if (empty($cartItems)) {
                return new JsonResponse(['success' => false, 'error' => 'Le panier est vide'], 400);
            }
            
            // Séparation des items du panier
            $menuMainItems = [];      // Produits avec est_menu = 1 (plat principal)
            $menuBoissonItems = [];   // Produits avec est_menu_boisson = 1 (boisson)
            $normalItems = [];        // Le reste des produits

            foreach ($cartItems as $item) {
                $produitId = $item['produitId'];
                $estMenu = $connection->fetchOne('SELECT est_menu FROM produit WHERE id = ?', [$produitId]);
                $estMenuBoisson = $connection->fetchOne('SELECT est_menu_boisson FROM produit WHERE id = ?', [$produitId]);
                
                if ($estMenu && !$estMenuBoisson) {
                    $menuMainItems[] = $item;
                } elseif ($estMenuBoisson && !$estMenu) {
                    $menuBoissonItems[] = $item;
                } else {
                    $normalItems[] = $item;
                }
            }
            
            // Initialisation des totaux et du numéro de ligne
            $totalPriceWithoutReduction = 0;
            $totalPriceWithReduction    = 0;
            $orderLines = [];
            $orderLineNumber = 1;
            $reductionValue = null;
            
            // Traitement des menus composés (association du premier plat avec la première boisson, etc.)
            $pairedMenus = min(count($menuMainItems), count($menuBoissonItems));
            for ($i = 0; $i < $pairedMenus; $i++) {
                $mainItem = $menuMainItems[$i];
                $boissonItem = $menuBoissonItems[$i];
                
                // Récupération du prix du plat principal (on prend le premier record dans "avoir")
                $tailleIdMain = $connection->fetchOne('SELECT taille_id FROM avoir WHERE produit_id = ? LIMIT 1', [$mainItem['produitId']]);
                $prixMain = $connection->fetchOne('SELECT prix FROM avoir WHERE produit_id = ? AND taille_id = ?', [$mainItem['produitId'], $tailleIdMain]);
                
                // Récupération du prix de la boisson en fonction de la taille passée (ex: "50cl")
                $tailleIdBoisson = $connection->fetchOne('SELECT id FROM taille WHERE unite = ?', [$boissonItem['taille']]);
                if (!$tailleIdBoisson) {
                    return new JsonResponse(['success' => false, 'error' => "Taille invalide pour le produit ID " . $boissonItem['produitId']], 400);
                }
                $prixBoisson = $connection->fetchOne('SELECT prix FROM avoir WHERE produit_id = ? AND taille_id = ?', [$boissonItem['produitId'], $tailleIdBoisson]);
                
                if ($prixMain === false || $prixMain === null || $prixBoisson === false || $prixBoisson === null) {
                    return new JsonResponse(['success' => false, 'error' => "Prix introuvable pour les produits constituant le menu"], 400);
                }
                
                // La quantité est supposée identique pour le plat et la boisson
                $quantity = $mainItem['quantity'];
                
                $menuPriceWithoutReduction = ($prixMain + $prixBoisson) * $quantity;
                $totalPriceWithoutReduction += $menuPriceWithoutReduction;
                
                // Récupération de la réduction depuis le produit boisson
                $reduction = $connection->fetchOne('SELECT valeur FROM produit WHERE id = ? AND est_menu_boisson = 1', [$boissonItem['produitId']]);

                if ($reduction !== false && $reduction !== null) {
                    // Appliquer la réduction une seule fois
                    $menuPriceWithReduction = ($prixMain + $prixBoisson - $reduction) * $quantity;
                    $totalPriceWithReduction += $menuPriceWithReduction;
                } else {
                    $menuPriceWithReduction = $menuPriceWithoutReduction;
                    $totalPriceWithReduction += $menuPriceWithoutReduction;
                }
                
                // Ajout des lignes de commande pour le menu composé
                // Ligne pour le plat principal
                $orderLines[] = [
                    'produit_id'   => $mainItem['produitId'],
                    'taille_id'    => $tailleIdMain,
                    'quantite'     => $quantity,
                    'prix'         => $prixMain, 
                    'numero_ordre' => $orderLineNumber++,
                    'type'         => 'menu'
                ];

                // Ligne pour la boisson
                $orderLines[] = [
                    'produit_id'   => $boissonItem['produitId'],
                    'taille_id'    => $tailleIdBoisson,
                    'quantite'     => $quantity,
                    'prix'         => $prixBoisson - ($reduction ?? 0),
                    'numero_ordre' => $orderLineNumber++,
                    'type'         => 'menu'
                ];
            }
            
            // Traitement des produits restants (non associés en menu)
            $restItems = array_merge($normalItems,
                array_slice($menuMainItems, $pairedMenus),
                array_slice($menuBoissonItems, $pairedMenus)
            );
            foreach ($restItems as $item) {
                $tailleId = $connection->fetchOne('SELECT id FROM taille WHERE unite = ?', [$item['taille']]);
                if (!$tailleId) {
                    return new JsonResponse(['success' => false, 'error' => "Taille invalide pour le produit ID {$item['produitId']}"], 400);
                }
                $prix = $connection->fetchOne('SELECT prix FROM avoir WHERE produit_id = ? AND taille_id = ?', [$item['produitId'], $tailleId]);
                if ($prix === false || $prix === null) {
                    return new JsonResponse(['success' => false, 'error' => "Prix non trouvé pour le produit ID {$item['produitId']}"], 400);
                }
                $linePrice = $prix * $item['quantity'];
                $totalPriceWithoutReduction += $linePrice;
                $totalPriceWithReduction    += $linePrice;
                
                $orderLines[] = [
                    'produit_id'   => $item['produitId'],
                    'taille_id'    => $tailleId,
                    'quantite'     => $item['quantity'],
                    'prix'         => $prix,
                    'numero_ordre' => $orderLineNumber++,
                    'type'         => 'normal'
                ];
            }
            
            // Insertion de la commande dans la table "commande"
            $orderDate = new \DateTime();
            $orderTime = new \DateTime();
            $statutId = 1;
            $connection->insert('commande', [
                'client_id'       => $clientId,
                'statut_id'       => $statutId,
                'numero_commande' => '',
                'heure'           => $orderTime->format('Y-m-d H:i:s'),
                'date'            => $orderDate->format('Y-m-d'),
                'prix_total'      => $totalPriceWithReduction,
            ]);
            $commandeId = $connection->lastInsertId();

            // Stocker l'ID de la commande dans la session pour la redirection vers /commande
            $request->getSession()->set('commande_id', $commandeId);

            $orderNumber = str_pad($commandeId, 3, '0', STR_PAD_LEFT);
            $connection->update('commande', [
                'numero_commande' => $orderNumber,
            ], ['id' => $commandeId]);
            
            // Insertion de chaque ligne dans la table "ligne_commande"
            foreach ($orderLines as $line) {
                $connection->insert('ligne_commande', [
                    'produit_id'   => $line['produit_id'],
                    'taille_id'    => $line['taille_id'],
                    'commande_id'  => $commandeId,
                    'numero_ordre' => $line['numero_ordre'],
                    'quantite'     => $line['quantite'],
                    'prix'         => $line['prix'],
                    'numero_menu'  => $line['type'] === 'menu' ? $orderNumber : null,
                ]);
            }
            
            // Vidage du panier côté session
            $request->getSession()->remove('panier');
            
            return new JsonResponse([
                'success' => true,
                'orderNumber' => $orderNumber,
                'prix_total_sans_reduction' => $totalPriceWithoutReduction,
                'prix_total_avec_reduction'  => $totalPriceWithReduction,
                'valeur' => $reductionValue
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
