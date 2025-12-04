<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RoundThrows;

/**
 * Contract for round throws repository.
 */
interface RoundThrowsRepositoryInterface
{
    /**
     * @param array<string, mixed>       $criteria
     * @param array<string, string>|null $orderBy
     * @param int|null                   $limit
     * @param int|null                   $offset
     *
     * @return RoundThrows[]|array<object>
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array;

    /**
     * @param array<string, mixed>       $criteria
     * @param array<string, string>|null $orderBy
     *
     * @return RoundThrows|null
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?RoundThrows;

    /**
     * @param array<string, mixed> $criteria
     *
     * @return int
     */
    public function count(array $criteria): int;

    /**
     * @param int $gameId
     *
     * @return RoundThrows|null
     */
    public function findEntityLatestForGame(int $gameId): ?RoundThrows;

    /**
     * @param int $gameId
     * @param int $playerId
     *
     * @return RoundThrows|null
     */
    public function findLatestForGameAndPlayer(int $gameId, int $playerId): ?RoundThrows;

    /**
     * @param int $gameId
     *
     * @return array<int, int>
     */
    public function getRoundsPlayedForGame(int $gameId): array;

    /**
     * @param int $gameId
     *
     * @return array<int, float>
     */
    public function getTotalScoreForGame(int $gameId): array;

    /**
     * @param int $gameId
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRoundAveragesForGame(int $gameId): array;

    /**
     * @param int $gameId
     *
     * @return array<int, int>
     */
    public function getLastRoundNumberForGame(int $gameId): array;

    /**
     * @param int    $gameId
     * @param int    $limit
     * @param int    $offset
     * @param string $sortField
     * @param string $direction
     *
     * @return array<int, array{
     *     playerId:int,
     *     username:string,
     *     gamesPlayed:string,
     *     totalValue:string,
     *     roundsFinished:string,
     *     scoreAverage:string|null
     * }>
     */
    public function getPlayerStatistics(
        int $limit,
        int $offset,
        string $sortField = 'average',
        string $direction = 'DESC'
    ): array;

    /**
     * @return int
     */
    public function countPlayersWithFinishedRounds(): int;

    /**
     * @param int $gameId
     *
     * @return array<string, mixed>|null
     */
    public function findLatestForGame(int $gameId): ?array;
}
