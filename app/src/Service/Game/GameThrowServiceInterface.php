<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\ThrowRequest;
use App\Entity\Game;
use App\Exception\ApiExceptionInterface;

/**
 * Interface for game throw service operations.
 */
interface GameThrowServiceInterface
{
    /**
     * Record a throw in the game.
     * Updates the game state, calculates scores, checks for bust conditions,
     * and determines if a player has won.
     *
     * @param Game         $game The game to record the throw in
     * @param ThrowRequest $dto  The throw data (player, value, double/triple flags)
     *
     * @throws ApiExceptionInterface If player not found in game or player already threw 3 times
     *
     * @return void
     */
    public function recordThrow(Game $game, ThrowRequest $dto): void;

    /**
     * Undo the last throw in the game.
     * Removes the last throw, restores player scores, resets positions,
     * and potentially changes the game status back to Started.
     *
     * @param Game $game The game to undo the last throw in
     *
     * @return void
     */
    public function undoLastThrow(Game $game): void;
}
