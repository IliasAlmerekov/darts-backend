<?php

namespace App\Controller;

use App\Entity\GamePlayers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\HttpFoundation\Request;
class SecurityController extends AbstractController
{
    #[Route(path: '/login/{id}', name: 'app_login', methods:('GET'))]
    public function login(int $id, AuthenticationUtils $authenticationUtils, Request $request): Response
    {
        $request->getSession()->set('login_game_id', $id);

        $error = $authenticationUtils->getLastAuthenticationError();

        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route(path: '/game/join', name: 'app_game_join', methods: ['POST'])]
    public function joinGame(Request $request, EntityManagerInterface $entityManager): Response
    {
        $gameId = $request->request->get('game_id');
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException('Sie müssen angemeldet sein');
        }

        $gamePlayer = new GamePlayers();
        $gamePlayer->setGameId($gameId);
        $gamePlayer->setPlayerId($user->getId());

        $entityManager->persist($gamePlayer);
        $entityManager->flush();

        return $this->redirectToRoute('success');
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
