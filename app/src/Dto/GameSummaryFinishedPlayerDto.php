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
 * Finished player item for game summary responses.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class GameSummaryFinishedPlayerDto
{
    /**
     * @param int|null    $playerId
     * @param string|null $username
     * @param int|null    $position
     * @param int|null    $placement
     * @param int|null    $roundsPlayed
     * @param float       $roundAverage
     */
    public function __construct(
        public ?int $playerId,
        public ?string $username,
        public ?int $position,
        public ?int $placement,
        #[OA\Property(description: 'Aufnahmen, die der Spieler in diesem Spiel geworfen hat.')]
        public ?int $roundsPlayed,
        #[OA\Property(description: 'Drei-Dart-Durchschnitt: Punkte geteilt durch geworfene Darts, mal drei. Eine Aufnahme mit Bust zählt 0 Punkte und 3 Darts.')]
        public float $roundAverage,
    ) {
    }
}
