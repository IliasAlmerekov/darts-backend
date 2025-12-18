<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Annotation\Groups;

/**
 * Response DTO for aggregated player stats.
 */
final class PlayerStatsResponseDto
{
    /**
     * @param int                 $limit
     * @param int                 $offset
     * @param int                 $total
     * @param list<PlayerStatsDto> $items
     */
    public function __construct(
        #[Groups(['stats:read'])]
        public int $limit,
        #[Groups(['stats:read'])]
        public int $offset,
        #[Groups(['stats:read'])]
        public int $total,
        #[Groups(['stats:read'])]
        public array $items,
    ) {
    }
}

