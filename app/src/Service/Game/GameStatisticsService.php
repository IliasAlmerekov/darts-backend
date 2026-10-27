<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\PlayerStatsDto;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Util\SqlResultValueAccessor;
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

        return array_map(self::createPlayerStatsDto(...), $rows);
    }

    /**
     * @param int $playerId
     *
     * @return PlayerStatsDto|null
     */
    #[Override]
    public function getPlayerStatsForPlayer(int $playerId): ?PlayerStatsDto
    {
        $row = $this->roundThrowsRepository->getPlayerStatisticsForPlayer($playerId);

        return null !== $row ? self::createPlayerStatsDto($row) : null;
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @return PlayerStatsDto
     */
    private static function createPlayerStatsDto(array $row): PlayerStatsDto
    {
        $playerId = self::resolveIntValue($row, ['playerId', 0]);
        $username = self::resolveStringValue($row, ['username', 'name', 1]);
        $gamesPlayed = self::resolveIntValue($row, ['gamesPlayed', 2]);
        $average = self::resolveAverage($row);

        return new PlayerStatsDto(
            $playerId,
            $username,
            $gamesPlayed,
            $average
        );
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<int|string>        $keys
     *
     * @return int
     */
    private static function resolveIntValue(array $row, array $keys): int
    {
        if (false === SqlResultValueAccessor::has($row, $keys)) {
            return 0;
        }

        return (int) SqlResultValueAccessor::find($row, $keys);
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<int|string>        $keys
     *
     * @return string
     */
    private static function resolveStringValue(array $row, array $keys): string
    {
        if (false === SqlResultValueAccessor::has($row, $keys)) {
            return '';
        }

        return (string) SqlResultValueAccessor::find($row, $keys);
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @return float
     */
    private static function resolveAverage(array $row): float
    {
        $value = SqlResultValueAccessor::find($row, ['scoreAverage', 5]);

        return null !== $value ? (float) $value : 0.0;
    }
}
