<?php

namespace App\Controller;

use App\Entity\GamePlayers;
use App\Repository\GamePlayersRepository;
use App\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\HttpFoundation\Request;
class SecurityController extends AbstractController
{
    #[Route(path: '/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils, Request $request): Response
    {
        $user = $this->getUser();

        if($user){
            return $this->redirectToRoute('login_success');
        }

        $error = $authenticationUtils->getLastAuthenticationError();

        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route('/login/success', name: 'login_success')]
    public function loginSuccess( Request $request, EntityManagerInterface $entityManager, InvitationRepository $invitationRepository, GamePlayersRepository $gamePlayersRepository): Response
    {
        $user = $this->getUser();

        if ($user && in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->redirectToRoute('room_list');
        }

        $session = $request->getSession();
        if ($session->has('invitation_uuid')) {
            $uuid = $session->get('invitation_uuid');
            $invitation = $invitationRepository->findOneBy(['uuid' => $uuid]);
            $gameId = $invitation->getGameId();
            $user = $this->getUser();
            $userId = $user->getId();
            if ($gamePlayersRepository->findOneBy(['gameId' => $gameId, 'playerId' => $userId])) {
                return $this->redirectToRoute('waiting_room');
            }
            $gamePlayer = new GamePlayers();
            $gamePlayer->setGameId($gameId);
            $gamePlayer->setPlayerId($userId);

            $entityManager->persist($gamePlayer);
            $entityManager->flush();
            $session->remove('invitation_uuid');
            return $this->redirectToRoute('waiting_room');
        }
        return $this->render('player/stats.html.twig', [
            'message' => 'Player stats coming soon'
        ]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
