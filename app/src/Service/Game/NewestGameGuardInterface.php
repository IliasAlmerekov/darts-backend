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

/**
 * Lets undo, reopen and rematch, which abort the other started games (ADR 0004), act only on
 * the newest game, so an older game never aborts the game in progress (ADR 0005).
 */
interface NewestGameGuardInterface
{
    /**
     * Throws unless the game is the newest game and has one of the allowed statuses. Must be
     * called inside the request's transaction, before anything is written.
     *
     * @param Game             $game
     * @param list<GameStatus> $allowedStatuses
     *
     * @return void
     *
     * @throws GameNotNewestException
     */
    public function assertNewest(Game $game, array $allowedStatuses): void;
}
