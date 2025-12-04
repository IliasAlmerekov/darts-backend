<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PlayerStatsDto;
use App\Repository\RoundThrowsRepository;

/**
 * Provides aggregated game statistics.
 */
final readonly class GameStatisticsService
{
    /**
     * @param RoundThrowsRepository $roundThrowsRepository
     */
    public function __construct(private RoundThrowsRepository $roundThrowsRepository)
    {
    }

    /**
     * @param int    $limit
     * @param int    $offset
     * @param string $sortField
     * @param string $sortDirection
     *
     * @return PlayerStatsDto[]
     */
    public function getPlayerStats(int $limit, int $offset, string $sortField, string $sortDirection): array
    {
        $rows = $this->roundThrowsRepository->getPlayerStatistics($limit, $offset, $sortField, $sortDirection);
        return array_map(static function (array $row): PlayerStatsDto {
            $rounds = (int) $row['roundsFinished'];
            $total = (float) $row['totalValue'];
            $average = $rounds > 0 ? $total / (float) $rounds : 0.0;
            return new PlayerStatsDto(
                (int) $row['playerId'],
                (string) $row['username'],
                (int) $row['gamesPlayed'],
                $average
            );
        }, $rows);
    }
}
