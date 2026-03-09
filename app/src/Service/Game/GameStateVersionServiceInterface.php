<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;

/**
 * Builds a deterministic version hash for a game state.
 */
interface GameStateVersionServiceInterface
{
    /**
     * @param Game $game
     *
     * @return string
     */
    public function buildStateVersion(Game $game): string;
}
