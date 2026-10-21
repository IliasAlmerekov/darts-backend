<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use App\Enum\GameStatus;
use App\Exception\Game\GameNotNewestException;
use App\Repository\GameRepositoryInterface;
use Override;

/**
 * Reads the newest game id with a shared lock and compares it with the target game.
 *
 * @psalm-suppress UnusedClass Reason: service is auto-wired by the container and used through DI.
 */
final readonly class NewestGameGuard implements NewestGameGuardInterface
{
    /**
     * @param GameRepositoryInterface $gameRepository
     *
     * @psalm-suppress PossiblyUnusedMethod Reason: constructor is used by Symfony autowiring.
     */
    public function __construct(private GameRepositoryInterface $gameRepository)
    {
    }

    /**
     * @param Game             $game
     * @param list<GameStatus> $allowedStatuses
     *
     * @return void
     *
     * @throws GameNotNewestException
     */
    #[Override]
    public function assertNewest(Game $game, array $allowedStatuses): void
    {
        if (!in_array($game->getStatus(), $allowedStatuses, true)
            || $game->getGameId() !== $this->gameRepository->findHighestGameId()) {
            throw new GameNotNewestException($allowedStatuses);
        }
    }
}
