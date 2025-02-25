<?php
namespace App\Controller;

use App\Entity\Client;
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

        return $this->render('connexion/index.html.twig');
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



    #[Route('/logout', name: 'logout')]
    public function logout(Request $request): Response
    {
        $request->getSession()->remove('user');
        return $this->redirectToRoute('connexion');
    }
}


