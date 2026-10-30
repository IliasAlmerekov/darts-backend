<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Game;

/** Carries the recorded throw and its game. */
final readonly class ThrowRecordingResultDto
{
    /**
     * @param \App\Entity\RoundThrows $latestThrow
     * @param Game                    $game
     */
    public function __construct(public \App\Entity\RoundThrows $latestThrow, public Game $game)
    {
    }
}
