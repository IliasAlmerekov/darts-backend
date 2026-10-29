<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\StartGameRequest;
use App\Entity\Game;
use App\Exception\ApiExceptionInterface;

/**
 * Interface for game start service operations.
 */
interface GameStartServiceInterface
{
    /**
     * Start a game with the given settings. Aborts every other started game (ADR 0004).
     *
     * Opens its own transaction through LockedGameTransactionInterface::runWithRetry() and
     * locks the game and its seats until it ends.
     *
     * @param Game             $game The game to start
     * @param StartGameRequest $dto  Game start configuration
     *
     * @throws ApiExceptionInterface If game cannot be started (e.g., not enough players)
     *
     * @return Game the locked, started game; read the response from it, not from $game
     */
    public function start(Game $game, StartGameRequest $dto): Game;
}
