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
     * @param int|null    $roundsPlayed
     * @param float       $roundAverage
     */
    public function __construct(
        public ?int $playerId,
        public ?string $username,
        public ?int $position,
        public ?int $roundsPlayed,
        public float $roundAverage,
    ) {
    }
}
