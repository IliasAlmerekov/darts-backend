<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Repository\GamePlayersRepository;
use App\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller to handle user login and logout.
 */
final class SecurityController extends AbstractController
{
    #[Route(path: 'api/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        $user = $this->getUser();
        if ($user) {
            return $this->redirectToRoute('login_success');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();
        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route('api/login/success', name: 'login_success')]
    public function loginSuccess(
        Request $request,
        EntityManagerInterface $entityManager,
        InvitationRepository $invitationRepository,
        GamePlayersRepository $gamePlayersRepository
    ): Response {
        $user = $this->getUser();
// Ensure a user is an instance of a User entity
        if (!$user instanceof User) {
            return $this->json(['error' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Admin redirect
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json([
                'success' => true,
                'roles' => $user->getStoredRoles(),
                'id' => $user->getId(),
                'username' => $user->getUserIdentifier(),
                'redirect' => '/start'
            ], Response::HTTP_OK, ['X-Accel-Buffering' => 'no']);
        }

        // Invitation redirect + Logic
        $session = $request->getSession();
        if ($session->has('invitation_uuid')) {
            $uuid = $session->get('invitation_uuid');
            $invitation = $invitationRepository->findOneBy(['uuid' => $uuid]);
            if ($invitation === null) {
                return $this->json(['error' => 'Invitation not found'], Response::HTTP_NOT_FOUND);
            }

            $gameId = $invitation->getGameId();
            $userId = $user->getId();
            try {
    // Add player to game if not already in
                if (!$gamePlayersRepository->findOneBy(['game' => $gameId, 'player' => $userId])) {
                    $gamePlayer = new GamePlayers();
                    $gamePlayer->setGame($entityManager->getReference(Game::class, $gameId));
                    $gamePlayer->setPlayer($entityManager->getReference(User::class, $userId));
                    $entityManager->persist($gamePlayer);
                    $entityManager->flush();
                }
            } catch (ORMException) {
                return $this->json([
                'error' => 'Database error occurred while adding player to game'
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            return $this->json([
                'success' => true,
                'roles' => $user->getStoredRoles(),
                'id' => $user->getId(),
                'username' => $user->getUserIdentifier(),
                'redirect' => '/joined'
            ], Response::HTTP_OK, ['X-Accel-Buffering' => 'no']);
        }


        // Default player redirect
        return $this->json([
            'success' => true,
            'roles' => $user->getStoredRoles(),
            'id' => $user->getId(),
            'username' => $user->getUserIdentifier(),
            'redirect' => '/playerprofile'
        ]);
    }

    #[Route(path: 'api/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new LogicException(
            'This method can be blank - it will be intercepted by the logout key on your firewall.'
        );
    }
}
