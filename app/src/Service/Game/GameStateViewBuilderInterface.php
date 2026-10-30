<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\GameResponseDto;
use App\Entity\Game;

/**
 * Builds the game state view: the game response DTO, the active player, and the state version.
 *
 * @phpstan-type RoundStateSnapshot array<int, array{throwsCount:int,lastThrowNumber:int|null,lastThrowValue:int|null,lastThrowBust:bool}>
 */
interface GameStateViewBuilderInterface
{


    /**
     * Create a complete game response DTO from a game entity.
     * Includes all players, their scores, throw history, and game settings.
     *
     * @param Game $game The game entity
     *
     * @return GameResponseDto Complete game data transfer object
     *
     * @throws \App\Exception\Game\GameIdMissingException If game ID is null
     */
    public function createGameDto(Game $game): GameResponseDto;

    /**
     * Build a stable version hash for quick game-state change detection.
     * Used by clients that poll frequently to skip unchanged payloads.
     *
     * @param Game     $game          The game entity
     * @param int|null $latestThrowId Already-known latest throw id to avoid redundant lookup
     *
     * @return string Deterministic version hash for the current game state
     */
    public function buildStateVersion(Game $game, ?int $latestThrowId = null): string;
}
