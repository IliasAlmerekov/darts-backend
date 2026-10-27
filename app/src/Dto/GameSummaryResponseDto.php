<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

use OpenApi\Attributes as OA;

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
        #[OA\Property(description: 'Aufnahmen, die der Sieger geworfen hat; `roundsPlayed` seines Eintrags in `finishedPlayers`.')]
        public int $winnerRoundsPlayed,
        #[OA\Property(description: 'Drei-Dart-Durchschnitt des Siegers; `roundAverage` seines Eintrags in `finishedPlayers`.')]
        public float $winnerRoundAverage,
        public array $finishedPlayers,
    ) {
    }
}
