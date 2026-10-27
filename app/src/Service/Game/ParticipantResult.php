<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\ParticipantTotalsDto;
use App\Entity\GamePlayers;

/**
 * A participant's result in a game: placement order, display name and three-dart average.
 * The game summary and the player's Profile both use it, so the two always agree.
 */
final class ParticipantResult
{
    /**
     * Orders seats by placement, seats without one last by remaining score.
     *
     * @param GamePlayers $a
     * @param GamePlayers $b
     *
     * @return int
     */
    public static function compareByPlacement(GamePlayers $a, GamePlayers $b): int
    {
        $placementA = $a->getPlacement();
        $placementB = $b->getPlacement();
        if (null === $placementA && null === $placementB) {
            return ($a->getScore() ?? PHP_INT_MAX) <=> ($b->getScore() ?? PHP_INT_MAX);
        }

        if (null === $placementA) {
            return 1;
        }

        if (null === $placementB) {
            return -1;
        }

        return $placementA <=> $placementB;
    }

    /**
     * @param GamePlayers $seat
     *
     * @return string|null
     */
    public static function displayName(GamePlayers $seat): ?string
    {
        if (null === $seat->getPlayer()) {
            return null;
        }

        $name = PlayerDisplayName::forSeat($seat);

        return '' !== $name ? $name : null;
    }

    /**
     * The three-dart average: points divided by darts thrown, times three. A participant who
     * threw nothing averages 0.
     *
     * @param ParticipantTotalsDto|null $totals
     *
     * @return float
     */
    public static function threeDartAverage(?ParticipantTotalsDto $totals): float
    {
        return null !== $totals && $totals->dartsThrown > 0
            ? $totals->totalScore * 3.0 / (float) $totals->dartsThrown
            : 0.0;
    }
}
