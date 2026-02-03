<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\Game;
use App\Entity\User;
use App\Exception\Game\GameIdMissingException;
use App\Exception\Security\SecurityAccessDeniedException;
use App\Exception\Security\UserNotAuthenticatedException;
use App\Repository\GamePlayersRepositoryInterface;
use Override;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Enforces game-related authorization rules.
 */
final readonly class GameAccessService implements GameAccessServiceInterface
{
    /**
     * @param Security                     $security
     * @param GamePlayersRepositoryInterface $gamePlayersRepository
     */
    public function __construct(private Security $security, private GamePlayersRepositoryInterface $gamePlayersRepository)
    {
    }

    /**
     * @return User
     */
    #[Override]
    public function requireAuthenticatedUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UserNotAuthenticatedException();
        }

        return $user;
    }

    /**
     * @return User
     */
    #[Override]
    public function assertAdmin(): User
    {
        $user = $this->requireAuthenticatedUser();
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new SecurityAccessDeniedException();
        }

        return $user;
    }

    /**
     * @param Game $game
     *
     * @return User
     */
    #[Override]
    public function assertPlayerInGameOrAdmin(Game $game): User
    {
        $user = $this->requireAuthenticatedUser();
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return $user;
        }

        $gameId = $game->getGameId();
        if (null === $gameId) {
            throw new GameIdMissingException();
        }

        $userId = $user->getId();
        if (null === $userId || !$this->gamePlayersRepository->isPlayerInGame($gameId, $userId)) {
            throw new SecurityAccessDeniedException();
        }

        return $user;
    }

    /**
     * @param User $user
     * @param int  $playerId
     *
     * @return void
     */
    #[Override]
    public function assertPlayerMatches(User $user, int $playerId): void
    {
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return;
        }

        $userId = $user->getId();
        if (null === $userId || $userId !== $playerId) {
            throw new SecurityAccessDeniedException();
        }
    }
}
