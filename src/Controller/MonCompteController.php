<?php
namespace App\Controller;

use App\Entity\Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
class MonCompteController extends AbstractController
{
    #[Route('/mon_compte', name: 'mon_compte')]
    public function index(EntityManagerInterface $em, SessionInterface $session, Request $request): Response
    {
        $user = $session->get('user');
        if (!$user) {
            return $this->redirectToRoute('connexion');
        }

        $client = $em->getRepository(Client::class)->find($user['id']);

        if ($request->isMethod('POST')) {
            $errors = [];
            $data = $request->request->all();

            // Vérification de l'ancien mot de passe
            if (!password_verify($data['old_password'], $client->getMdp())) {
                $errors[] = ['message' => 'Ancien mot de passe incorrect'];
            }

            // Vérification que l'email n'est pas déjà utilisé par un autre client
            $existingEmail = $em->getRepository(Client::class)->findOneBy(['email' => $data['email']]);
            if ($existingEmail && $existingEmail->getId() !== $client->getId()) {
                $errors[] = ['message' => 'L\'adresse e-mail est déjà utilisée.'];
            }

            // Vérification que le login n'est pas déjà utilisé par un autre client
            $existingLogin = $em->getRepository(Client::class)->findOneBy(['login' => $data['login']]);
            if ($existingLogin && $existingLogin->getId() !== $client->getId()) {
                $errors[] = ['message' => 'Le login est déjà utilisé.'];
            }

            // Validation du nouveau mot de passe
            if (!empty($data['new_password'])) {
                if ($data['new_password'] !== $data['confirm_password']) {
                    $errors[] = ['message' => 'Les nouveaux mots de passe ne correspondent pas'];
                } elseif (!preg_match('/^(?=.*[A-Z])(?=.*\d).{6,}$/', $data['new_password'])) {
                    $errors[] = ['message' => 'Le mot de passe doit contenir au moins 6 caractères, une majuscule et un chiffre'];
                }
            }

            if (count($errors) > 0) {
                return $this->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $errors
                ]);
            }

            // Mise à jour des informations
            $client->setNom($data['nom'])
                ->setPrenom($data['prenom'])
                ->setEmail($data['email'])
                ->setLogin($data['login']);

            // Mise à jour du mot de passe si fourni
            if (!empty($data['new_password'])) {
                $client->setMdp(password_hash($data['new_password'], PASSWORD_BCRYPT));
            }

            $em->flush();

            return $this->json([
                'success' => true,
                'message' => 'Mise à jour effectuée avec succès'
            ]);
        }

        return $this->render('mon_compte/index.html.twig', [
            'client' => $client
        ]);
    }



    #[Route('/supprimer_compte', name: 'supprimer_compte')]
    public function supprimerCompte(EntityManagerInterface $em, SessionInterface $session): Response
    {
        $user = $session->get('user');
        $client = $em->getRepository(Client::class)->find($user['id']);
        
        $client->setDateSuppr(new \DateTime());
        $em->flush();
        
        $session->remove('user');
        $this->addFlash('warning', 'Votre compte a été marqué pour suppression');
        return $this->redirectToRoute('connexion');
    }
}