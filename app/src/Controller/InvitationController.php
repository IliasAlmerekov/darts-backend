<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\Invitation;
use App\Entity\User;
use App\Repository\GamePlayersRepository;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * This class handles invitation-related actions such as creating invitations,
 * joining via invitation links, and processing invitations.
 * Also get as JSON responses for API requests.
 */
final class InvitationController extends AbstractController
{
    /**
     * @param int $id
     * @param Request $request
     * @param EntityManagerInterface $entityManager
     * @param InvitationRepository $invitationRepository
     * @param GamePlayersRepository $gamePlayersRepository
     * @param UserRepository $userRepository
     * @return Response
     */
    #[Route('api/invite/create/{id}', name: 'create_invitation')]
    public function createInvitation(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        InvitationRepository $invitationRepository,
        GamePlayersRepository $gamePlayersRepository,
        UserRepository $userRepository
    ): Response {
        $invitation = $invitationRepository->findOneBy(['gameId' => $id]);
        if (null === $invitation) {
            $uuid = Uuid::v4();
            $invitation = new Invitation();
            $invitation->setUuid($uuid);
            $invitation->setGameId($id);
            $entityManager->persist($invitation);
            $entityManager->flush();
        }

        $players = $gamePlayersRepository->findByGameId($id);
        $playerIds = array_map(fn($player) => $player->getPlayer()?->getId(), $players);
        $users = $userRepository->findBy(['id' => $playerIds]);
        $invitationLink = $this->generateUrl('join_invitation', ['uuid' => $invitation->getUuid()]);
        if (str_contains($request->headers->get('Accept') ?? '', 'application/json')) {
            return $this->json([
                'success' => true,
                'gameId' => $id,
                'invitationLink' => $invitationLink
            ], Response::HTTP_OK, ['X-Accel-Buffering' => 'no']);
        }

        return $this->render('invitation/index.html.twig', [
            'invitationLink' => $invitationLink,
            'users' => $users
        ]);
    }

    #[Route('api/invite/join/{uuid}', name: 'join_invitation')]
    public function joinInvitation(string $uuid, InvitationRepository $invitationRepository, Request $request): Response
    {
        $invitation = $invitationRepository->findOneBy(['uuid' => $uuid]);
        if (!$invitation) {
            return $this->render('invitation/not_found.html.twig');
        }
        $session = $request->getSession();
        $session->remove('invitation_uuid');
        $session->set('invitation_uuid', $uuid);
        $session->set('game_id', $invitation->getGameId());
        return $this->redirect('http://localhost:5173/');
    }

    /**
     * @throws ORMException
     */
    #[Route('api/invite/process', name: 'process_invitation')]
    public function processInvitation(
        Request $request,
        GamePlayersRepository $gamePlayersRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }
        $gameId = $request->getSession()->get('game_id');
        if (!$gameId) {
            return $this->redirectToRoute('room_list');
        }

        $userId = $user->getId();
        if ($userId === null) {
            return $this->redirectToRoute('app_login');
        }

        if (!$gamePlayersRepository->isPlayerInGame($gameId, $userId)) {
            $gamePlayer = new GamePlayers();
            $gamePlayer->setGame($entityManager->getReference(Game::class, $gameId));
            $gamePlayer->setPlayer($entityManager->getReference(User::class, $userId));
            $entityManager->persist($gamePlayer);
            $entityManager->flush();
        }

        $request->getSession()->remove('invitation_uuid');
        $request->getSession()->remove('game_id');
        return $this->redirectToRoute('waiting_room');
    }
}
