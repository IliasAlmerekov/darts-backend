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
 * Unified summary payload for finished-game responses.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class GameSummaryResponseDto
{
    /**
     * @param int                                $gameId
     * @param string|null                        $finishedAt
     * @param GameSummaryWinnerDto|null          $winner
     * @param int                                $winnerRoundsPlayed
     * @param float                              $winnerRoundAverage
     * @param list<GameSummaryFinishedPlayerDto> $finishedPlayers
     */
    public function __construct(
        public int $gameId,
        public ?string $finishedAt,
        public ?GameSummaryWinnerDto $winner,
        public int $winnerRoundsPlayed,
        public float $winnerRoundAverage,
        public array $finishedPlayers,
    ) {
    }
}
