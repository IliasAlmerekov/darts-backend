<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\PlayerStatsDto;
use App\Repository\RoundThrowsRepositoryInterface;
use Override;

/**
 * Provides aggregated game statistics.
 */
final readonly class GameStatisticsService implements GameStatisticsServiceInterface
{
    /**
     * @param RoundThrowsRepositoryInterface $roundThrowsRepository
     */
    public function __construct(private RoundThrowsRepositoryInterface $roundThrowsRepository)
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
    #[Override]
    public function getPlayerStats(int $limit, int $offset, string $sortField, string $sortDirection): array
    {
        $rows = $this->roundThrowsRepository->getPlayerStatistics($limit, $offset, $sortField, $sortDirection);

        return array_map(static function (array $row): PlayerStatsDto {
            $playerId = self::resolveIntValue($row, ['playerId', 0]);
            $username = self::resolveStringValue($row, ['username', 'name', 1]);
            $gamesPlayed = self::resolveIntValue($row, ['gamesPlayed', 2]);
            $rounds = self::resolveIntValue($row, ['roundsFinished', 4]);
            $average = self::resolveAverage($row, $rounds);

            return new PlayerStatsDto(
                $playerId,
                $username,
                $gamesPlayed,
                $average
            );
        }, $rows);
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<int|string>        $keys
     *
     * @return int
     */
    private static function resolveIntValue(array $row, array $keys): int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return (int) $row[$key];
            }
        }

        return 0;
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<int|string>        $keys
     *
     * @return string
     */
    private static function resolveStringValue(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return (string) $row[$key];
            }
        }

        return '';
    }

    /**
     * @param array<array-key, mixed> $row
     * @param int                     $rounds
     *
     * @return float
     */
    private static function resolveAverage(array $row, int $rounds): float
    {
        if (array_key_exists('scoreAverage', $row) || array_key_exists(5, $row)) {
            $value = $row['scoreAverage'] ?? $row[5];

            return null !== $value ? (float) $value : 0.0;
        }

        $total = self::resolveFloatValue($row, ['totalValue', 3]);

        return $rounds > 0 ? $total / (float) $rounds : 0.0;
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<int|string>        $keys
     *
     * @return float
     */
    private static function resolveFloatValue(array $row, array $keys): float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return (float) $row[$key];
            }
        }

        return 0.0;
    }
}
