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
                    // Stockage en session
                    $session->set('user', [
                        'id' => $client->getId(),
                        'prenom' => $client->getPrenom(),
                    ]);
                    return $this->redirectToRoute('home');
                } else {
                    $this->addFlash('error', 'Email ou mot de passe incorrect.');
                }
            } elseif ($action === 'signup') {
                $client = new Client();
                $client->setPrenom($request->get('first_name'))
                    ->setNom($request->get('last_name'))
                    ->setLogin($request->get('login'))
                    ->setEmail($request->get('email_create'))
                    ->setMdp(password_hash($request->get('password_create'), PASSWORD_BCRYPT))
                    ->setComment($request->get('comment') ?? '');

                $em->persist($client);
                $em->flush();

                // Connexion automatique après inscription
                $session->set('user', [
                    'id' => $client->getId(),
                    'prenom' => $client->getPrenom(),
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
        return $this->render('home/index.html.twig', [
            'prenom' => $prenom
        ]);
    }

    #[Route('/logout', name: 'logout')]
    public function logout(Request $request): Response
    {
        $request->getSession()->remove('user');
        return $this->redirectToRoute('home');
    }
}
