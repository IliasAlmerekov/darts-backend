<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\PlayerStatsDto;

/**
 * Interface for game statistics service.
 */
interface GameStatisticsServiceInterface
{
    /**
     * Get player statistics.
     *
     * @param int    $limit
     * @param int    $offset
     * @param string $sortField
     * @param string $sortDirection
     *
     * @return PlayerStatsDto[]
     */
    public function getPlayerStats(int $limit, int $offset, string $sortField, string $sortDirection): array;
}
