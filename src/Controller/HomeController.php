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
        // Récupération des données nécessaires
        $categories = $categorieRepository->findAll();
        $allPossede = $possedeRepository->findAll();
        $subcategoriesByCategory = [];

        // Construction de la structure de sous-catégories par catégorie
        foreach ($allPossede as $possede) {
            $category = $possede->getCategorie();
            if ($category) {
                $categoryId = $category->getId();
                $subcategoriesByCategory[$categoryId][] = $possede->getSousCategorie();
            }
        }

        return $this->render('home/index.html.twig', [
            'produits' => $produitRepository->findAllWithTailleAndPrix(),
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

            // Validate input data
            if (!isset($data['produitId']) || !isset($data['avoirIds']) || !isset($data['quantity'])) {
                return new JsonResponse(['success' => false, 'message' => 'Données manquantes'], 400);
            }

            // Create the command and set required fields
            $commande = new Commande();
            // Vérifier que l'utilisateur est connecté


            $commande->setNumeroCommande(uniqid('CMD_')); // Unique command number
            $commande->setDate(new \DateTime()); // Current date
            $commande->setHeure(new \DateTime()); // Current time

            // Set status
            $statut = $this->entityManager->getRepository(Statut::class)->findOneBy(['libelle' => 'En cours de préparation']);
            if (!$statut) {
                return new JsonResponse(['success' => false, 'message' => 'Statut non trouvé'], 400);
            }
            $commande->setStatut($statut);

            // Track total price
            $total = 0.0;

            // Generate menu number
            $numeroMenu = (int)time();

            // Retrieve menu product
            $menuProduit = $this->entityManager->getRepository(Produit::class)->find($data['produitId']);
            if (!$menuProduit || $menuProduit->getCategorie()->getId() !== 7) {
                return new JsonResponse(['success' => false, 'message' => 'Produit non valide'], 400);
            }

            // Add regular line items
            foreach ($data['avoirIds'] as $avoirId) {
                $avoir = $this->entityManager->getRepository(Avoir::class)->find($avoirId);
                if (!$avoir) {
                    return new JsonResponse(['success' => false, 'message' => "Avoir $avoirId non trouvé"], 400);
                }

                $ligne = new LigneCommande();
                $ligne->setProduit($avoir->getProduit());
                $ligne->setTaille($avoir->getTaille());
                $ligne->setQuantite($data['quantity']);
                $ligne->setPrix($avoir->getPrix()); // Unit price
                $ligne->setNumeroMenu($numeroMenu);
                $ligne->setCommande($commande);

                // Add to total (unit price * quantity)
                $total += $avoir->getPrix() * $data['quantity'];

                $this->entityManager->persist($ligne);
            }

            // Add discount line item
            $ligneReduction = new LigneCommande();
            $ligneReduction->setProduit($menuProduit);
            $ligneReduction->setQuantite($data['quantity']);
            $discountPerUnit = -$menuProduit->getValeur(); // Unit discount (e.g., -5€ per menu)
            $ligneReduction->setPrix($discountPerUnit); // Unit price (not total)
            $ligneReduction->setNumeroMenu($numeroMenu);
            $ligneReduction->setCommande($commande);

            // Add discount to total (unit discount * quantity)
            $total += $discountPerUnit * $data['quantity'];

            $this->entityManager->persist($ligneReduction);

            // Set total price on the command
            $commande->setPrixTotal($total);

            // Persist and save everything
            $this->entityManager->persist($commande);
            $this->entityManager->flush();

            return new JsonResponse(['success' => true]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false, 
                'message' => 'Erreur interne : ' . $e->getMessage()
            ], 500);
        }
    }

}
