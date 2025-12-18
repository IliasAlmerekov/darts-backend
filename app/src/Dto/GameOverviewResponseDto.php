<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

/**
 * Response DTO for finished games overview.
 *
 * @psalm-type GameOverviewItems = list<GameOverviewItemDto>
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class GameOverviewResponseDto
{
    /**
     * @param int                       $limit
     * @param int                       $offset
     * @param int                       $total
     * @param list<GameOverviewItemDto> $items
     */
    public function __construct(
        public int $limit,
        public int $offset,
        public int $total,
        public array $items,
    ) {
    }
}
