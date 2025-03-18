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
use App\Entity\Produit;
use Symfony\Component\HttpFoundation\Session\SessionInterface;


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
        
        // Récupération des items pour la composition du menu

        // Snack : produits éligibles aux menus (confiseries)
        $snackItems = $this->entityManager->createQueryBuilder()
        ->select('a.id as avoirId', 'p.nom', 'p.ref_produit as imageUrl', 't.unite as taille', 'a.prix as prix', 'MIN(t.id) as minTailleId')
        ->from(Produit::class, 'p')
        ->join(Avoir::class, 'a', 'WITH', 'a.produit = p.id')
        ->join('a.taille', 't')
        ->where('p.en_ligne = 1')
        ->andWhere('p.est_menu = 1')
        ->groupBy('p.nom, t.unite, a.id')
        ->orderBy('minTailleId', 'ASC')
        ->addOrderBy('p.nom', 'ASC')
        ->getQuery()
        ->getArrayResult();

        // Boisson : produits éligibles aux menus avec un prix fixé à 2€
        $drinkItems = $this->entityManager->createQueryBuilder()
        ->select('a.id as avoirId', 'p.nom', 'p.ref_produit as imageUrl', 't.unite as taille', "2 as prix", 'MIN(t.id) as minTailleId')
        ->from(Produit::class, 'p')
        ->join(Avoir::class, 'a', 'WITH', 'a.produit = p.id')
        ->join('a.taille', 't')
        ->where('p.en_ligne = 1')
        ->andWhere('p.est_menu_boisson = 1')
        ->groupBy('p.nom, t.unite, a.id')
        ->orderBy('minTailleId', 'ASC')
        ->addOrderBy('p.nom', 'ASC')
        ->getQuery()
        ->getArrayResult();

        return $this->render('home/index.html.twig', [
            'groupedProducts'      => $groupedProducts,
            'categories'           => $categories,
            'subcategoriesByCategory' => $subcategoriesByCategory,
            'snackItems'           => $snackItems,
            'drinkItems'           => $drinkItems
        ]);
    }
    
    #[Route('/api/menu-items', name: 'api_menu_items')]
    public function getMenuItems(Request $request): JsonResponse
    {
        $type = $request->query->get('type');
        
        $query = $this->entityManager->createQueryBuilder()
            ->select('a.id as avoirId', 'p.nom', 'p.ref_produit as imageUrl', 't.unite as taille')
            ->addSelect('CASE WHEN :type = \'drink\' THEN 2 ELSE a.prix END as prix')
            ->from(Produit::class, 'p')
            ->join(Avoir::class, 'a', 'WITH', 'a.produit = p.id')
            ->join('a.taille', 't')
            ->where('p.en_ligne = 1')
            ->orderBy('p.nom', 'ASC')
            ->groupBy('p.nom, t.unite') // Groupement par nom et taille
            ->setParameter('type', $type);

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
            $discountDisplay = $totalDiscount;
            $total = ($snackAvoir->getPrix() + $drinkAvoir->getPrix() - $totalDiscount) * $data['quantity'];
    
            $session = $request->getSession();
            $panier = $session->get('panier', []);
    
            $menuEntry = [
                'type' => 'menu',
                'snack' => [
                    'id' => $snackAvoir->getProduit()->getId(),
                    'nom' => $snackAvoir->getProduit()->getNom(),
                    'taille' => $snackAvoir->getTaille()->getUnite(),
                    'prix' => $snackAvoir->getPrix()
                ],
                'drink' => [
                    'id' => $drinkAvoir->getProduit()->getId(),
                    'nom' => $drinkAvoir->getProduit()->getNom(),
                    'taille' => $drinkAvoir->getTaille()->getUnite(),
                    'prix' => $drinkAvoir->getPrix()
                ],
                'quantity' => $data['quantity'],
                'total' => $total,
                'valeur' => $discountDisplay
            ];
            // Génération d'un identifiant unique pour ce menu
            $menuEntry['menuId'] = 'menu-' . $snackAvoir->getProduit()->getId() . '-' . time();
    
            // Fusionner le nouvel item avec le panier existant
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
    #[Route('/panier/ajouter-produit', name: 'ajouter_produit', methods: ['POST'])]
    public function ajouterProduit(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['produitId'], $data['taille'], $data['quantity'], $data['price'], $data['nom'])) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Données manquantes.'
            ], 400);
        }

        $session = $request->getSession();
        $panier = $session->get('panier', []);

        // Recherche d'un produit existant
        $found = false;
        foreach ($panier as &$item) {
            if ($item['type'] === 'normal' && $item['produitId'] == $data['produitId'] && $item['taille'] === $data['taille']) {
                $item['quantity'] += $data['quantity'];
                $found = true;
                break;
            }
        }

        if (!$found) {
            $panier[] = [
                'type' => 'normal',
                'produitId' => $data['produitId'],
                'taille' => $data['taille'],
                'quantity' => $data['quantity'],
                'price' => $data['price'],
                'nom' => $data['nom']
            ];
        }

        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
            'panier' => $panier
        ]);
    }
    /**
     * Retirer un produit normal du panier.
     *
     * @param Request $request
     * @param SessionInterface $session
     * @return Response
     */
    #[Route('/panier/retirer-produit', methods: ['POST'])]
    public function retirerProduit(Request $request, SessionInterface $session): Response
    {
        $data = json_decode($request->getContent(), true);
        $produitId = $data['produitId'] ?? null;
        $taille = $data['taille'] ?? null;

        if ($produitId === null || $taille === null) {
            return $this->json(['success' => false, 'error' => 'Données invalides.']);
        }

        $panier = $session->get('panier', []);

        foreach ($panier as $key => $item) {
            if (isset($item['type']) && $item['type'] === 'normal'
                && isset($item['produitId']) && $item['produitId'] == $produitId
                && isset($item['taille']) && $item['taille'] === $taille) {
                unset($panier[$key]);
            }
        }

        $panier = array_values($panier);
        $session->set('panier', $panier);

        return $this->json(['success' => true, 'panier' => $panier]);
    }


    /**
     * Retirer un menu du panier.
     *
     * @param Request $request
     * @param SessionInterface $session
     * @return Response
     */
    #[Route('/panier/retirer-menu', methods: ['POST'])]
    public function retirerMenu(Request $request, SessionInterface $session): Response
    {
        $data = json_decode($request->getContent(), true);
        $menuId = $data['menuId'] ?? null;

        if ($menuId === null) {
            return $this->json(['success' => false, 'error' => 'Données invalides.']);
        }

        $panier = $session->get('panier', []);

        foreach ($panier as $key => $item) {
            if (isset($item['type']) && $item['type'] === 'menu'
                && isset($item['menuId']) && $item['menuId'] == $menuId) {
                unset($panier[$key]);
            }
        }

        $panier = array_values($panier);
        $session->set('panier', $panier);

        return $this->json(['success' => true, 'panier' => $panier]);
    }

    #[Route('/panier/get', name: 'get_panier', methods: ['GET'])]
    public function getPanier(Request $request): JsonResponse
    {
        $session = $request->getSession();
        return new JsonResponse(['panier' => $session->get('panier', [])]);
    }
    #[Route('/panier/vider', methods: ['POST'])]
    public function viderPanier(Request $request): Response
    {
        $session = $request->getSession();
        $session->remove('panier');
        return $this->json(['success' => true]);
    }

}
