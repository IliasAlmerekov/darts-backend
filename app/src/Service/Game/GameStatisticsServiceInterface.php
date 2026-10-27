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
     * @param string $sortField     `average`, `gamesPlayed` or `name` (display name); ties follow the player id
     * @param string $sortDirection
     *
     * @return PlayerStatsDto[]
     */
    public function getPlayerStats(int $limit, int $offset, string $sortField, string $sortDirection): array;

    /**
     * The same statistics row as getPlayerStats() for one player, or null when the player
     * threw nothing in a finished game.
     *
     * @param int $playerId
     *
     * @return PlayerStatsDto|null
     */
    public function getPlayerStatsForPlayer(int $playerId): ?PlayerStatsDto;
}
