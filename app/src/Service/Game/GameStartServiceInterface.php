<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\StartGameRequest;
use App\Entity\Game;
use InvalidArgumentException;

/**
 * Interface for game start service operations.
 */
interface GameStartServiceInterface
{
    /**
     * Start a game with the given settings.
     *
     * @param Game             $game The game to start
     * @param StartGameRequest $dto  Game start configuration
     *
     * @throws InvalidArgumentException If game cannot be started (e.g., not enough players)
     *
     * @return void
     */
    public function start(Game $game, StartGameRequest $dto): void;
}
