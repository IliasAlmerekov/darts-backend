<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PlayerStatsDto;
use App\Repository\RoundThrowsRepository;

class GameStatisticsService
{
    public function __construct(
        private readonly RoundThrowsRepository $roundThrowsRepository,
    ) {
    }

    /**
     * @return PlayerStatsDto[]
     */
    public function getPlayerStats(int $limit, int $offset, string $sortField, string $sortDirection): array
    {
        $rows = $this->roundThrowsRepository->getPlayerStatistics($limit, $offset, $sortField, $sortDirection);

        return array_map(static function (array $row): PlayerStatsDto {
            $rounds = (int) $row['roundsFinished'];
            $total = (float) $row['totalValue'];
            $average = $rounds > 0 ? $total / $rounds : 0.0;

            return new PlayerStatsDto(
                (int) $row['playerId'],
                (string) $row['username'],
                (int) $row['gamesPlayed'],
                $average
            );
        }, $rows);
    }
}
