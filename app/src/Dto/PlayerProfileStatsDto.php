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
 * A player's own statistics. gamesPlayed and scoreAverage are computed like their row in
 * GET /api/players/stats; the rest comes from their throws in the same games.
 * Percentages run from 0 to 100, rounded to one decimal.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class PlayerProfileStatsDto
{
    /**
     * @param int                                 $gamesPlayed      finished games with at least one finished round
     * @param float|null                          $scoreAverage     null when gamesPlayed is 0
     * @param int                                 $wins             counted games with placement 1
     * @param float|null                          $winRate          null when gamesPlayed is 0
     * @param float|null                          $checkoutRate     checkout hits per 100 attempts, null without attempts
     * @param int                                 $checkoutHits
     * @param int                                 $checkoutAttempts darts thrown while one dart could check out
     * @param list<PlayerProfileSegmentHitsDto>   $hitDistribution  always 22 entries: 1 to 20, BULL, BULLSEYE
     * @param PlayerProfileMostHitSegmentDto|null $mostHitSegment   null when no dart hit a segment
     * @param list<PlayerProfileDoubleDto>        $favoriteDoubles  top 3 in double-out games, empty without a hit
     * @param PlayerProfileFinishDto|null         $highestFinish    null without a finish
     */
    public function __construct(
        public int $gamesPlayed,
        public ?float $scoreAverage,
        public int $wins,
        public ?float $winRate,
        public ?float $checkoutRate,
        public int $checkoutHits,
        public int $checkoutAttempts,
        public array $hitDistribution,
        public ?PlayerProfileMostHitSegmentDto $mostHitSegment,
        public array $favoriteDoubles,
        public ?PlayerProfileFinishDto $highestFinish,
    ) {
    }
}
