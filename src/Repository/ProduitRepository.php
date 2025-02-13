<?php

namespace App\Repository;

use App\Entity\Produit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProduitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Produit::class);
    }

    /**
     * Récupère tous les produits avec leurs tailles et prix (via Avoir)
     * @return Produit[]
     */
    public function findAllWithTailleAndPrix(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.avoirs', 'a') // Relation entre Produit et Avoir
            ->addSelect('a')
            ->leftJoin('a.taille', 't') // Relation entre Avoir et Taille
            ->addSelect('t')
            ->orderBy('p.nom', 'ASC') // Trie par nom (modifiable)
            ->getQuery()
            ->getResult();
    }
}
