<?php
namespace App\Controller;

use App\Entity\Client;
use App\Entity\Commande;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;


class ConnexionController extends AbstractController
{
    #[Route('/', name: 'connexion')]
    public function index(Request $request, EntityManagerInterface $em, SessionInterface $session): Response
    {
        if ($request->isMethod('POST')) {
            $action = $request->get('action');
            
            if ($action === 'login') {
                $email = $request->get('email');
                $password = $request->get('password');

                $client = $em->getRepository(Client::class)->findOneBy(['email' => $email]);

                if ($client && password_verify($password, $client->getMdp())) {
                    if ($client->getDateSuppr() !== null) {
                        $this->addFlash('error', 'Ce compte a été supprimé. Souhaitez-vous le réactiver ?');
                        return $this->render('connexion/debloquer_compte.html.twig', ['email' => $email]);
                    }
                    // Stockage en session
                    $session->set('user', [
                        'id' => $client->getId(),
                        'prenom' => $client->getPrenom(),
                        'nom' => $client->getNom(),
                    ]);
                    return $this->redirectToRoute('home');
                } else {
                    $this->addFlash('error', 'Email ou mot de passe incorrect.');
                    return $this->redirectToRoute('connexion', ['form' => 'login']);
                }
            } elseif ($action === 'signup') {
                // Récupération des données du formulaire
                $email = $request->get('email_create');
                $login = $request->get('login');
                $password = $request->get('password_create');

                if (!preg_match('/^(?=.*[A-Z])(?=.*\d).{6,}$/', $password)) {
                    $this->addFlash('error', 'Le mot de passe doit contenir au moins 6 caractères, une majuscule et un chiffre.');
                    return $this->redirectToRoute('connexion', ['form' => 'signup']);
                }
                
                // Vérification de l'unicité de l'email
                $existingClientByEmail = $em->getRepository(Client::class)->findOneBy(['email' => $email]);
                if ($existingClientByEmail) {
                    $this->addFlash('error', 'Cet email est déjà utilisé.');
                    return $this->redirectToRoute('connexion', ['form' => 'signup']);
                }
                
                // Vérification de l'unicité du login
                $existingClientByLogin = $em->getRepository(Client::class)->findOneBy(['login' => $login]);
                if ($existingClientByLogin) {
                    $this->addFlash('error', 'Ce login est déjà utilisé.');
                    return $this->redirectToRoute('connexion', ['form' => 'signup']);
                }

                // Création du client
                $client = new Client();
                $client->setPrenom($request->get('first_name'))
                    ->setNom($request->get('last_name'))
                    ->setLogin($login)
                    ->setEmail($email)
                    ->setMdp(password_hash($password, PASSWORD_BCRYPT))
                    ->setComment($request->get('comment') ?? '');

                $em->persist($client);
                $em->flush();

                // Connexion automatique après inscription
                $session->set('user', [
                    'id' => $client->getId(),
                    'prenom' => $client->getPrenom(),
                    'nom' => $client->getNom(),
                ]);
                return $this->redirectToRoute('home');
            }
        }

         // Récupérer le paramètre 'form' (login par défaut)
        $formType = $request->query->get('form', 'login');

        return $this->render('connexion/index.html.twig', [
            'form' => $formType,
        ]);
    }

    #[Route('/home', name: 'home')]
    public function home(Request $request): Response
    {
        $user = $request->getSession()->get('user');
        $prenom = $user ? $user['prenom'] : 'visiteur';
        $nom = $user ? $user['nom'] : 'visiteur';
        return $this->render('home/index.html.twig', [
            'prenom' => $prenom,
            'nom' => $nom
        ]);
    }
    #[Route('/debloquer_compte', name: 'debloquer_compte')]
    public function debloquerCompte(Request $request, EntityManagerInterface $em): Response
    {
        $email = $request->get('email');
        $password = $request->get('password');
        
        $client = $em->getRepository(Client::class)->findOneBy(['email' => $email]);

        if ($client && password_verify($password, $client->getMdp())) {
            // Si mot de passe valide, redirige vers la page de confirmation
            return $this->redirectToRoute('confirmation_debloquer', ['email' => $email]);
        }

        $this->addFlash('error', 'Identifiants incorrects');
        return $this->redirectToRoute('connexion');
    }
    #[Route('/confirmation_debloquer/{email}', name: 'confirmation_debloquer')]
    public function confirmationDebloquer(Request $request, EntityManagerInterface $em, $email): Response
    {
        // Récupérer le client en fonction de l'email
        $client = $em->getRepository(Client::class)->findOneBy(['email' => $email]);

        if (!$client) {
            $this->addFlash('error', 'Compte introuvable.');
            return $this->redirectToRoute('connexion');
        }

        // Si le formulaire est soumis et la confirmation est "oui"
        if ($request->isMethod('POST') && $request->get('confirmation') === 'oui') {
            $client->setDateSuppr(null); // Réactive le compte en mettant date_suppr à NULL
            $em->flush();
            $this->addFlash('success', 'Compte réactivé avec succès !');
            return $this->redirectToRoute('connexion');
        }

        return $this->render('connexion/confirmation_debloquer.html.twig', [
            'email' => $email
        ]);
    }
    #[Route('/commande_passer', name: 'commande_passer')]
    public function commandesPassees(EntityManagerInterface $em, SessionInterface $session): Response
    {
        // Vérifier si l'utilisateur est connecté
        $user = $session->get('user');
        if (!$user) {
            $this->addFlash('error', 'Vous devez être connecté pour voir vos commandes.');
            return $this->redirectToRoute('connexion');
        }

        // Récupérer l'ID de l'utilisateur
        $clientId = $user['id'];

        // Déterminer la date du jour
        $today = new \DateTime();
        $today->setTime(0, 0, 0); // Début de la journée

        // Requête pour récupérer les commandes du jour pour cet utilisateur
        $commandes = $em->getRepository(Commande::class)->createQueryBuilder('c')
            ->where('c.client = :clientId')
            ->andWhere('c.date >= :today')
            ->setParameter('clientId', $clientId)
            ->setParameter('today', $today)
            ->orderBy('c.date', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('commande/commandes_passees.html.twig', [
            'commandes' => $commandes,
        ]);
    }




    #[Route('/logout', name: 'logout')]
    public function logout(Request $request): Response
    {
        $request->getSession()->remove('user');
        return $this->redirectToRoute('connexion');
    }

    
}


