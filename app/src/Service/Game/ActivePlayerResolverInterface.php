<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;

/**
 * Resolves the current active player for a game state.
 *
 * @phpstan-type RoundStateSnapshot array<int, array{throwsCount:int,lastThrowNumber:int|null,lastThrowValue:int|null,lastThrowBust:bool}>
 */
interface ActivePlayerResolverInterface
{
    /**
     * @param Game                    $game
     * @param RoundStateSnapshot|null $roundStateSnapshot
     *
     * @return int|null
     */
    public function resolveActivePlayer(Game $game, ?array $roundStateSnapshot = null): ?int;
}
