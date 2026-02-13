<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

/**
 * Delta for scoreboard-related changes after a throw.
 */
final class ScoreboardDeltaDto
{
    /**
     * @param list<ScoreboardPlayerDeltaDto> $changedPlayers
     * @param int|null                       $winnerId
     * @param string                         $status
     * @param int                            $currentRound
     */
    public function __construct(
        public array $changedPlayers,
        public ?int $winnerId,
        public string $status,
        public int $currentRound,
    ) {
    }
}
