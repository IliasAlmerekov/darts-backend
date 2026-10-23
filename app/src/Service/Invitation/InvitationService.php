<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Invitation;

use App\Entity\Game;
use App\Entity\Invitation;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Exception\Game\GameAlreadyStartedException;
use App\Exception\Game\GameJoinNotAllowedException;
use App\Exception\Game\GameNotFoundException;
use App\Exception\Game\GameRoomFullException;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\GameRepositoryInterface;
use App\Repository\InvitationRepositoryInterface;
use App\Repository\UserRepositoryInterface;
use App\Service\Game\PlayerDisplayName;
use App\Service\Player\PlayerManagementServiceInterface;
use App\Service\Security\GameAccessServiceInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Service to handle invitation creation and related data.
 *
 * @psalm-suppress UnusedClass Reason: service is wired and used via DI.
 */
final readonly class InvitationService implements InvitationServiceInterface
{
    /**
     * @param InvitationRepositoryInterface    $invitationRepository
     * @param GamePlayersRepositoryInterface   $gamePlayersRepository
     * @param GameRepositoryInterface          $gameRepository
     * @param UserRepositoryInterface          $userRepository
     * @param PlayerManagementServiceInterface $playerManagementService
     * @param EntityManagerInterface           $entityManager
     * @param RouterInterface                  $router
     * @param GameAccessServiceInterface       $gameAccessService
     * @param string                           $frontendUrl
     */
    public function __construct(
        private InvitationRepositoryInterface $invitationRepository,
        private GamePlayersRepositoryInterface $gamePlayersRepository,
        private GameRepositoryInterface $gameRepository,
        private UserRepositoryInterface $userRepository,
        private PlayerManagementServiceInterface $playerManagementService,
        private EntityManagerInterface $entityManager,
        private RouterInterface $router,
        private GameAccessServiceInterface $gameAccessService,
        private string $frontendUrl,
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
        if (null === $game->getGameId()) {
            throw new GameNotFoundException();
        }

        return $this->entityManager->wrapInTransaction(function () use ($game): Invitation {
            if ($this->entityManager->contains($game)) {
                $this->entityManager->lock($game, LockMode::PESSIMISTIC_WRITE);
            }

            $candidate = $this->invitationRepository->findOneBy(['game' => $game]);
            if ($candidate instanceof Invitation) {
                return $candidate;
            }

            $invitation = new Invitation();
            $invitation->setUuid(Uuid::v4());
            $invitation->setGame($game);
            $this->entityManager->persist($invitation);
            $this->entityManager->flush();

            return $invitation;
        });
    }

    /**
     * @param Game $game
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function getInvitationPayload(Game $game): array
    {
        $this->gameAccessService->assertPlayerInGameOrAdmin($game);
        $gameId = $game->getGameId();
        if (null === $gameId) {
            return ['success' => false, 'message' => 'Game not found'];
        }

        $invitation = $this->createOrGetInvitation($game);
        try {
            $users = $this->getUsersForGame($game);
        } catch (Throwable) {
            // Keep invitation creation available even if participant projection fails due to schema drift.
            $users = [];
        }
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
     * @return array<int, array{id:int|null,username:string|null}>
     */
    #[Override]
    public function getUsersForGame(Game $game): array
    {
        $this->gameAccessService->assertPlayerInGameOrAdmin($game);
        $gameId = $game->getGameId();
        if (null === $gameId) {
            return [];
        }

        $players = $this->gamePlayersRepository->findByGameId($gameId);
        $users = [];
        foreach ($players as $player) {
            $user = $player->getPlayer();
            if (!$user instanceof User) {
                continue;
            }

            $name = PlayerDisplayName::forSeat($player);
            if ('' === $name) {
                continue;
            }

            $users[] = [
                'id' => $user->getId(),
                'username' => $name,
            ];
        }

        return $users;
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
        if (!$user instanceof User) {
            return new JsonResponse(['success' => false, 'redirect' => '/login'], Response::HTTP_UNAUTHORIZED);
        }

        $gameId = $session->get('game_id');
        if (!$gameId) {
            return new JsonResponse(['success' => false, 'redirect' => '/start'], Response::HTTP_BAD_REQUEST);
        }
        $gameId = (int) $gameId;
        $userId = $user->getId();
        if (null === $userId) {
            return new JsonResponse(['success' => false, 'redirect' => '/login'], Response::HTTP_UNAUTHORIZED);
        }

        // Whatever the join outcome, the invitation is spent: a stale one must not block later logins.
        try {
            return $this->joinGame($gameId, $userId, $user);
        } finally {
            $session->remove('invitation_uuid');
            $session->remove('game_id');
        }
    }

    /**
     * @param int  $gameId
     * @param int  $userId
     * @param User $user
     *
     * @return Response
     */
    private function joinGame(int $gameId, int $userId, User $user): Response
    {
        return $this->entityManager->wrapInTransaction(function () use ($gameId, $userId, $user): Response {
            $game = $this->gameRepository->find($gameId);
            if (!$game instanceof Game) {
                throw new GameNotFoundException();
            }

            // find() above took the snapshot. A rematch or start that committed while this
            // request waited for the lock is visible only to the reload and the locking reads.
            $this->gameRepository->refreshWithSeatsForUpdate($game);

            if (false === $this->gamePlayersRepository->isPlayerInGameForShare($gameId, $userId)) {
                $this->assertGameJoinableForGame($game);
                $this->playerManagementService->addPlayer($gameId, $userId);
            }

            return new JsonResponse([
                'success' => true,
                'roles' => $user->getStoredRoles(),
                'id' => $userId,
                'email' => $user->getEmail(),
                'username' => $user->getUsername(),
                'redirect' => rtrim($this->frontendUrl, '/').'/joined',
                'gameId' => $gameId,
            ], Response::HTTP_OK, ['X-Accel-Buffering' => 'no']);
        });
    }

    /**
     * @param Game $game
     *
     * @return void
     */
    private function assertGameJoinableForGame(Game $game): void
    {
        $gameId = $game->getGameId();
        if (null === $gameId) {
            throw new GameNotFoundException();
        }

        $status = $game->getStatus();
        if (GameStatus::Started === $status) {
            throw new GameAlreadyStartedException();
        }

        if (GameStatus::Lobby !== $status) {
            throw new GameJoinNotAllowedException($status);
        }

        if ($game->getGamePlayers()->count() >= Game::MAX_PARTICIPANTS) {
            throw new GameRoomFullException(Game::MAX_PARTICIPANTS);
        }
    }
}
