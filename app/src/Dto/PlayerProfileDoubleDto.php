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
 * A double a player checked out with in double-out games, and how often they tried it.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class PlayerProfileDoubleDto
{
    /**
     * @param string $double   D1 to D20 or BULLSEYE
     * @param int    $hits     checkouts with this double
     * @param int    $attempts darts thrown while the remaining score equalled this double
     * @param float  $hitRate  hits per 100 attempts, rounded to one decimal
     */
    public function __construct(
        public string $double,
        public int $hits,
        public int $attempts,
        public float $hitRate,
    ) {
    }
}
