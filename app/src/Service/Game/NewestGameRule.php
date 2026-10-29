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

/**
 * Only the newest game may be reopened or rematched (ADR 0005).
 */
final class NewestGameRule
{
    /**
     * Throws unless the game is the newest game and has one of the allowed statuses. Must be
     * called inside the request's transaction, before anything is written.
     *
     * @param Game                    $game
     * @param list<GameStatus>        $allowedStatuses
     * @param GameRepositoryInterface $games
     *
     * @return void
     *
     * @throws GameNotNewestException
     */
    public static function assert(Game $game, array $allowedStatuses, GameRepositoryInterface $games): void
    {
        if (!in_array($game->getStatus(), $allowedStatuses, true)
            || $game->getGameId() !== $games->findHighestGameId()) {
            throw new GameNotNewestException($allowedStatuses);
        }
    }
}
