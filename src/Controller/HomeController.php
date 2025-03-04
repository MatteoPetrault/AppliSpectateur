<?php

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
        // Récupérer les catégories triées par ordre
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

        $produits = $produitRepository->findBy(['en_ligne' => 1]);

        // Grouper les produits par catégorie
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


    /**
     * @Route("/product/get-price", name="product_get_price", methods={"GET"})
     */
    public function getPrice(int $produit_id, int $taille_id): JsonResponse
    {
        // Récupérer les entités 'Avoir' pour le produit et la taille spécifiés
        $avoir = $this->entityManager->getRepository(Avoir::class)->findOneBy([
            'produit' => $produit_id,
            'taille' => $taille_id,
        ]);

        if ($avoir) {
            return new JsonResponse([
                'prix' => $avoir->getPrix(),
            ]);
        }

        return new JsonResponse([
            'prix' => 0,  // Retourner 0 si le produit ou la taille n'existent pas
        ]);
    }
    #[Route('/panier/ajouter-menu', name: 'ajouter_menu', methods: ['POST'])]
    public function ajouterMenu(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!isset($data['produitId']) || !isset($data['avoirIds']) || !isset($data['quantity'])) {
                return new JsonResponse(['success' => false, 'message' => 'Données manquantes'], 400);
            }
            // Récupération du produit principal
            $menuProduit = $this->entityManager->getRepository(Produit::class)->find($data['produitId']);
            $reduction = $menuProduit->getValeur(); // valeur unitaire
                
            // Calcul du prix total du menu
            $composants = [];
            $prixTotal = 0;
            foreach ($data['avoirIds'] as $avoirId) {
                $avoir = $this->entityManager->getRepository(Avoir::class)->find($avoirId);
                $prixTotal += $avoir->getPrix();
                $composants[] = $avoir->getProduit()->getNom() . ' (' . $avoir->getTaille()->getUnite() . ')';
            }
            
            // Application de la réduction
            $prixTotal -= $reduction;
            $prixTotal = round($prixTotal, 2);
            
    
            // Stockage dans la session
            $session = $request->getSession();
            $panier = $session->get('panier', []);
            
            $menuData = [
                'type' => 'menu',
                'produit_id' => $menuProduit->getId(),
                'avoir_ids' => $data['avoirIds'],
                'quantite' => $data['quantity'],
                'prix_total' => $prixTotal,
                'composants' => implode(', ', $composants),
                'reduction' => $reduction
            ];
            
            $panier[] = $menuData; // Ajout comme nouvel élément
            $session->set('panier', $panier);
    
            return new JsonResponse([
                'success' => true,
                'prixTotal' => $prixTotal,
                'composants' => implode(' + ', $composants)
            ]);
    
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    

}
