<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

/**
 * One participant's throw totals in one game: the turns they threw, the darts those turns
 * count as, and their points. A turn with a bust scores 0 and counts as 3 darts.
 */
final readonly class ParticipantTotalsDto
{
    /**
     * @param int   $turns       the number of turns they threw
     * @param int   $dartsThrown the darts thrown, a bust turn counted as 3
     * @param float $totalScore  the points scored, a bust turn counted as 0
     */
    public function __construct(
        public int $turns,
        public int $dartsThrown,
        public float $totalScore,
    ) {
    }
}
