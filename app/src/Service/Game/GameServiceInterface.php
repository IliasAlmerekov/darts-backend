<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\GameResponseDto;
use App\Entity\Game;

/**
 * Interface for game service operations.
 */
interface GameServiceInterface
{
    /**
     * Calculate and return the id of the active player for the given game.
     *
     * Logic:
     * - Players are sorted by their position in the game
     * - A player is active if:
     *   * They have not yet reached a score of 0 (not won)
     *   * They have thrown less than 3 times in the current round
     *   * They are not bust in the current round
     *
     * @param Game $game The game entity
     *
     * @return int|null The id of the active player or null if no active player found
     */
    public function calculateActivePlayer(Game $game): ?int;

    /**
     * Create a complete game response DTO from a game entity.
     * Includes all players, their scores, throw history, and game settings.
     *
     * @param Game $game The game entity
     *
     * @return GameResponseDto Complete game data transfer object
     *
     * @throws \RuntimeException If game ID is null
     */
    public function createGameDto(Game $game): GameResponseDto;
}
