<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

/**
 * Item DTO for finished games overview.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class GameOverviewItemDto
{
    /**
     * @param int         $id
     * @param string|null $date
     * @param string|null $finishedAt
     * @param int         $playersCount
     * @param string|null $winnerName
     * @param int|null    $winnerId
     * @param int         $winnerRounds
     */
    public function __construct(
        public int $id,
        public ?string $date,
        public ?string $finishedAt,
        public int $playersCount,
        public ?string $winnerName,
        public ?int $winnerId,
        public int $winnerRounds,
    ) {
    }
}
