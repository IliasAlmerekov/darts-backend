<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Game;
use App\Entity\Invitation;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\InvitationRepositoryInterface;
use App\Repository\UserRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Service to handle invitation creation and related data.
 *
 * @psalm-suppress UnusedClass Reason: service is wired and used via DI.
 */
final readonly class InvitationService implements InvitationServiceInterface
{
    /**
     * @param InvitationRepositoryInterface  $invitationRepository
     * @param GamePlayersRepositoryInterface $gamePlayersRepository
     * @param UserRepositoryInterface        $userRepository
     * @param EntityManagerInterface         $entityManager
     * @param RouterInterface                $router
     */
    public function __construct(
        private InvitationRepositoryInterface $invitationRepository,
        private GamePlayersRepositoryInterface $gamePlayersRepository,
        private UserRepositoryInterface $userRepository,
        private EntityManagerInterface $entityManager,
        private RouterInterface $router,
    ) {
    }

    /**
     * @param Game $game
     *
     * @return Invitation
     */
    #[Override]
    public function createOrGetInvitation(Game $game): Invitation
    {
        $gameId = $game->getGameId();
        $invitation = null;
        if (null !== $gameId) {
            $candidate = $this->invitationRepository->findOneBy(['gameId' => $gameId]);
            if ($candidate instanceof Invitation) {
                $invitation = $candidate;
            }
        }

        if (null === $invitation) {
            $invitation = new Invitation();
            $invitation->setUuid(Uuid::v4());
            if (null !== $gameId) {
                $invitation->setGameId($gameId);
            }
            $this->entityManager->persist($invitation);
            $this->entityManager->flush();
        }

        return $invitation;
    }

    /**
     * @param Game $game
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function getInvitationPayload(Game $game): array
    {
        $gameId = $game->getGameId();
        if (null === $gameId) {
            return ['success' => false, 'message' => 'Game not found'];
        }

        $invitation = $this->createOrGetInvitation($game);
        $users = $this->getUsersForGame($game);
        $invitationLink = $this->router->generate('join_invitation', ['uuid' => $invitation->getUuid()]);

        return [
            'success' => true,
            'gameId' => $gameId,
            'invitationLink' => $invitationLink,
            'users' => $users,
        ];
    }

    /**
     * @param Game $game
     *
     * @return array<int, mixed>
     */
    #[Override]
    public function getUsersForGame(Game $game): array
    {
        $gameId = $game->getGameId();
        if (null === $gameId) {
            return [];
        }

        $players = $this->gamePlayersRepository->findByGameId($gameId);
        $playerIds = array_values(array_filter(array_map(
            static fn ($player) => $player->getPlayer()?->getId(),
            $players
        )));

        return [] === $playerIds ? [] : $this->userRepository->findBy(['id' => $playerIds]);
    }

    /**
     * @param SessionInterface $session
     * @param mixed            $user
     *
     * @return Response
     */
    #[Override]
    public function processInvitation(SessionInterface $session, mixed $user): Response
    {
        if (!$user instanceof \App\Entity\User) {
            return new RedirectResponse($this->router->generate('app_login'));
        }

        $gameId = $session->get('game_id');
        if (!$gameId) {
            return new RedirectResponse($this->router->generate('room_list'));
        }

        $userId = $user->getId();
        if (null === $userId) {
            return new RedirectResponse($this->router->generate('app_login'));
        }

        if (!$this->gamePlayersRepository->isPlayerInGame($gameId, $userId)) {
            $gamePlayer = new \App\Entity\GamePlayers();
            $gamePlayer->setGame($this->entityManager->getReference(Game::class, $gameId));
            $gamePlayer->setPlayer($this->entityManager->getReference(\App\Entity\User::class, $userId));
            $this->entityManager->persist($gamePlayer);
            $this->entityManager->flush();
        }

        $session->remove('invitation_uuid');
        $session->remove('game_id');

        return new RedirectResponse($this->router->generate('waiting_room'));
    }
}
