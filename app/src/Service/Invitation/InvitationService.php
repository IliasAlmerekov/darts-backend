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
use App\Exception\Security\UserNotAuthenticatedException;
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
     * @param InvitationRepositoryInterface    $invitationRepository
     * @param GamePlayersRepositoryInterface   $gamePlayersRepository
     * @param GameRepositoryInterface          $gameRepository
     * @param UserRepositoryInterface          $userRepository
     * @param PlayerManagementServiceInterface $playerManagementService
     * @param EntityManagerInterface           $entityManager
     * @param RouterInterface                  $router
     * @param GameAccessServiceInterface       $gameAccessService
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
        if (null === $gameId) {
            throw new GameNotFoundException();
        }

        return $this->entityManager->wrapInTransaction(function () use ($game, $gameId): Invitation {
            if ($this->entityManager->contains($game)) {
                $this->entityManager->lock($game, LockMode::PESSIMISTIC_WRITE);
            }

            // A locking read sees invitations committed after this transaction took its snapshot.
            $candidate = $this->invitationRepository->findOneByGameIdForShare($gameId);
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
        $this->gameAccessService->assertAdmin();
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
     * @return array<int, array{id:int|null,username:string|null}>
     */
    #[Override]
    public function getUsersForGame(Game $game): array
    {
        $this->gameAccessService->assertAdmin();
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
     * @param int  $gameId
     * @param User $user
     *
     * @return int
     */
    #[Override]
    public function joinInvitedGame(int $gameId, User $user): int
    {
        $userId = $user->getId();
        if (null === $userId) {
            throw new UserNotAuthenticatedException();
        }

        return $this->entityManager->wrapInTransaction(function () use ($gameId, $userId): int {
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

            return $gameId;
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
