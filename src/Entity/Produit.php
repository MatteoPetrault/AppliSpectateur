<?php

namespace App\Entity;

use App\Repository\ProduitRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProduitRepository::class)]
class Produit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\Column(nullable: true)]
    private ?float $prix = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ref_produit = null;

    #[ORM\Column(nullable: true)]
    private ?int $quantite_produit_restant = null;

    #[ORM\ManyToOne(inversedBy: 'produits')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Categorie $categorie = null;

    #[ORM\ManyToOne(inversedBy: 'produits')]
    private ?SousCategorie $sous_categorie = null;

    /**
     * @var Collection<int, Avoir>
     */
    #[ORM\OneToMany(targetEntity: Avoir::class, mappedBy: 'produit')]
    private Collection $avoirs;

    /**
     * @var Collection<int, LigneCommande>
     */
    #[ORM\OneToMany(targetEntity: LigneCommande::class, mappedBy: 'produit')]
    private Collection $ligneCommandes;

    #[ORM\Column]
    private ?bool $en_rupture = null;

    /**
     * @ORM\ManyToMany(targetEntity=Taille::class, mappedBy="produits")
     */
    private $tailles;

    public function __construct()
    {
        $this->avoirs = new ArrayCollection();
        $this->ligneCommandes = new ArrayCollection();
    }

    // Remove all methods related to "Appartient" (getAppartients, addAppartient, removeAppartient)

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    public function getPrix(): ?float
    {
        return $this->prix;
    }

    public function setPrix(?float $prix): static
    {
        $this->prix = $prix;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getRefProduit(): ?string
    {
        return $this->ref_produit;
    }

    public function setRefProduit(?string $ref_produit): static
    {
        $this->ref_produit = $ref_produit;
        return $this;
    }

    public function getQuantiteProduitRestant(): ?int
    {
        return $this->quantite_produit_restant;
    }

    public function setQuantiteProduitRestant(?int $quantite_produit_restant): static
    {
        $this->quantite_produit_restant = $quantite_produit_restant;
        return $this;
    }

    public function getCategorie(): ?Categorie
    {
        return $this->categorie;
    }

    public function setCategorie(?Categorie $categorie): static
    {
        $this->categorie = $categorie;
        return $this;
    }

    public function getSousCategorie(): ?SousCategorie
    {
        return $this->sous_categorie;
    }

    public function setSousCategorie(?SousCategorie $sous_categorie): static
    {
        $this->sous_categorie = $sous_categorie;
        return $this;
    }

    /**
     * @return Collection<int, Avoir>
     */
    public function getAvoirs(): Collection
    {
        return $this->avoirs;
    }

    public function addAvoir(Avoir $avoir): static
    {
        if (!$this->avoirs->contains($avoir)) {
            $this->avoirs->add($avoir);
            $avoir->setProduit($this);
        }
        return $this;
    }

    public function removeAvoir(Avoir $avoir): static
    {
        if ($this->avoirs->removeElement($avoir)) {
            if ($avoir->getProduit() === $this) {
                $avoir->setProduit(null);
            }
        }
        return $this;
    }

    public function isEnRupture(): ?bool
    {
        return $this->en_rupture;
    }

    public function setEnRupture(bool $en_rupture): static
    {
        $this->en_rupture = $en_rupture;
        return $this;
    }

    /**
     * @return Collection<int, LigneCommande>
     */
    public function getLigneCommandes(): Collection
    {
        return $this->ligneCommandes;
    }

    public function addLigneCommande(LigneCommande $ligneCommande): static
    {
        if (!$this->ligneCommandes->contains($ligneCommande)) {
            $this->ligneCommandes->add($ligneCommande);
            $ligneCommande->setProduit($this);
        }
        return $this;
    }

    public function removeLigneCommande(LigneCommande $ligneCommande): static
    {
        if ($this->ligneCommandes->removeElement($ligneCommande)) {
            if ($ligneCommande->getProduit() === $this) {
                $ligneCommande->setProduit(null);
            }
        }
        return $this;
    }
}