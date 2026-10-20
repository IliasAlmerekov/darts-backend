<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

/**
 * One participant's throw totals in one game: the last round they threw in, how many rounds
 * they threw in, and their score without busts.
 */
final readonly class ParticipantTotalsDto
{
    /**
     * @param int   $lastRound
     * @param int   $roundsPlayed
     * @param float $totalScore
     */
    public function __construct(
        public int $lastRound,
        public int $roundsPlayed,
        public float $totalScore,
    ) {
    }
}
