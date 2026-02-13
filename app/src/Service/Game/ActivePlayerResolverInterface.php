<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;

/**
 * Resolves the current active player for a game state.
 */
interface ActivePlayerResolverInterface
{
    /**
     * @param Game $game
     *
     * @return int|null
     */
    public function resolveActivePlayer(Game $game): ?int;
}
