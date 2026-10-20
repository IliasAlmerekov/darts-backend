<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

/**
 * @psalm-immutable
 * A player's own statistics, computed like their row in GET /api/players/stats.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class PlayerProfileStatsDto
{
    /**
     * @param int        $gamesPlayed  finished games with at least one finished round
     * @param float|null $scoreAverage null when gamesPlayed is 0
     */
    public function __construct(
        public int $gamesPlayed,
        public ?float $scoreAverage,
    ) {
    }
}
