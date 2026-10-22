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
 * A player's highest Finish.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class PlayerProfileFinishDto
{
    /**
     * @param int          $score  remaining score when the turn began
     * @param int          $darts  darts in the turn, 1 to 3
     * @param list<string> $throws the darts in order: S<n>, D<n>, T<n>, BULL, BULLSEYE or MISS
     */
    public function __construct(
        public int $score,
        public int $darts,
        public array $throws,
    ) {
    }
}
