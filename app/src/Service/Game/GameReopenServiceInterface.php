<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use App\Exception\ApiExceptionInterface;

/**
 * Interface for reopening finished games.
 */
interface GameReopenServiceInterface
{
    /**
     * Reopen a finished game and move it back to started state. Aborts every other started
     * game (ADR 0004). Must be called inside a transaction: it locks the game and its seats
     * until it ends.
     *
     * @param Game $game
     *
     * @throws ApiExceptionInterface
     *
     * @return void
     */
    public function reopen(Game $game): void;
}
