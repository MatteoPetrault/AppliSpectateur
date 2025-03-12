<?php
// src/Controller/HomeController.php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Repository\ProduitRepository;
use App\Repository\CategorieRepository;
use App\Repository\PossedeRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Avoir;
use App\Entity\LigneCommande;
use App\Entity\Produit;
use App\Entity\Commande;
use App\Entity\Statut;

class HomeController extends AbstractController
{
    private $entityManager;
    
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }
    
    #[Route('/home', name: 'home')]
    public function index(
        ProduitRepository $produitRepository,
        CategorieRepository $categorieRepository,
        PossedeRepository $possedeRepository
    ): Response {
        $categories = $categorieRepository->findAllOrderedByOrder();
        $allPossede = $possedeRepository->findAll();
        $subcategoriesByCategory = [];
        $groupedProducts = [];
        
        foreach ($allPossede as $possede) {
            $category = $possede->getCategorie();
            if ($category) {
                $categoryId = $category->getId();
                $subcategoriesByCategory[$categoryId][] = $possede->getSousCategorie();
            }
        }
        
        $produits = $produitRepository->findBy(['en_ligne' => 1], ['nom' => 'ASC']);
        
        foreach ($produits as $produit) {
            $categoryId = $produit->getCategorie()->getId();
            if ($categoryId !== 8) { // Exclure les menus
                $groupedProducts[$categoryId][] = $produit;
            }
        }
        
        return $this->render('home/index.html.twig', [
            'groupedProducts' => $groupedProducts,
            'categories' => $categories,
            'subcategoriesByCategory' => $subcategoriesByCategory
        ]);
    }
    
    #[Route('/api/menu-items', name: 'api_menu_items')]
    public function getMenuItems(Request $request): JsonResponse
    {
        $type = $request->query->get('type');
        
        $query = $this->entityManager->createQueryBuilder()
            ->select('a.id as avoirId', 'p.nom', 'p.valeur', 'a.prix', 't.unite as taille')
            ->from(Produit::class, 'p')
            ->join(Avoir::class, 'a', 'WITH', 'a.produit = p.id')
            ->join('a.taille', 't')
            ->where('p.en_ligne = 1');
    
        if ($type === 'snack') {
            $query->andWhere('p.est_menu = 1');
        } elseif ($type === 'drink') {
            $query->andWhere('p.est_menu_boisson = 1');
        }
    
        $items = $query->getQuery()->getArrayResult();
    
        return new JsonResponse($items);
    }
    
    #[Route('/panier/ajouter-menu', name: 'ajouter_menu', methods: ['POST'])]
    public function ajouterMenu(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
    
        if (!isset($data['snackId'], $data['drinkId'], $data['quantity'])) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Données manquantes. Requis : snackId, drinkId, quantity'
            ], 400);
        }
    
        try {
            $snackAvoir = $this->entityManager->getRepository(Avoir::class)->find($data['snackId']);
            $drinkAvoir = $this->entityManager->getRepository(Avoir::class)->find($data['drinkId']);
    
            if (!$snackAvoir || !$drinkAvoir) {
                $errors = [];
                if (!$snackAvoir) $errors[] = 'Snack introuvable';
                if (!$drinkAvoir) $errors[] = 'Boisson introuvable';
                
                return new JsonResponse([
                    'success' => false,
                    'error' => implode(' et ', $errors)
                ], 404);
            }
    
            if (!$snackAvoir->getProduit()->isEstMenu()) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Le snack sélectionné ne fait pas partie des menus'
                ], 400);
            }
    
            if (!$drinkAvoir->getProduit()->isEstMenuBoisson()) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'La boisson sélectionnée n\'est pas éligible aux menus'
                ], 400);
            }
    
            $snackDiscount = $snackAvoir->getProduit()->getValeur() ?? 0;
            $drinkDiscount = $drinkAvoir->getProduit()->getValeur() ?? 0;
            $totalDiscount = $snackDiscount + $drinkDiscount;
            $discountDisplay = $totalDiscount; // Affichage sous forme d'un seul chiffre            
            $total = ($snackAvoir->getPrix() + $drinkAvoir->getPrix() - $totalDiscount) * $data['quantity'];
    
            $session = $request->getSession();
            $panier = $session->get('panier', []);
    
            // Modifier la section où le menu est ajouté au panier
            $menuEntry = [
                'type' => 'menu',
                'snack' => [
                    'id' => $snackAvoir->getProduit()->getId(),
                    'nom' => $snackAvoir->getProduit()->getNom(),
                    'taille' => $snackAvoir->getTaille()->getUnite(),
                    'prix' => $snackAvoir->getPrix() // Conserver le prix original
                ],
                'drink' => [
                    'id' => $drinkAvoir->getProduit()->getId(),
                    'nom' => $drinkAvoir->getProduit()->getNom(),
                    'taille' => $drinkAvoir->getTaille()->getUnite(),
                    'prix' => $drinkAvoir->getPrix() // Conserver le prix original
                ],
                'quantity' => $data['quantity'],
                'total' => ($snackAvoir->getPrix() + $drinkAvoir->getPrix() - $totalDiscount) * $data['quantity'],
                'valeur' => $discountDisplay
            ];
            $menuEntry['menuId'] = 'menu-' . $snackAvoir->getProduit()->getId() . '-' . time();
    
            $panier[] = $menuEntry;
            $session->set('panier', $panier);
    
            return new JsonResponse([
                'success' => true,
                'total' => array_sum(array_column($panier, 'total')),
                'panier' => $panier,
                'valeur' => $discountDisplay
            ]);
    
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Erreur serveur : ' . $e->getMessage()
            ], 500);
        }
    }
}
