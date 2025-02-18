<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Repository\ProduitRepository;
use App\Repository\CategorieRepository;
use App\Repository\PossedeRepository;

class HomeController extends AbstractController
{
    #[Route('/home', name: 'home')]
    public function index(
        ProduitRepository $produitRepository,
        CategorieRepository $categorieRepository,
        PossedeRepository $possedeRepository
    ): Response
    {
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
    public function getPrice(int $produit_id, int $taille_id, EntityManagerInterface $em): JsonResponse
    {
        // Récupérer les entités 'Avoir' pour le produit et la taille spécifiés
        $avoir = $em->getRepository(Avoir::class)->findOneBy([
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
}