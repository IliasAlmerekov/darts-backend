<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RoundThrows;
use Doctrine\ORM\QueryBuilder;

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
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param array<string, mixed>       $criteria
     * @param array<string, string>|null $orderBy
     *
     * @return object|null
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object;

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
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
     * @psalm-suppress PossiblyUnusedReturnValue
     *
     * @param int $gameId
     * @param int $throwId
     *
     * @return RoundThrows|null
     */
    public function findLatestForGameBeforeThrow(int $gameId, int $throwId): ?RoundThrows;

    /**
     * @param int $gameId
     * @param int $playerId
     * @param int $throwId
     *
     * @return RoundThrows|null
     */
    public function findLatestForGameAndPlayerBeforeThrow(int $gameId, int $playerId, int $throwId): ?RoundThrows;

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param int $gameId
     *
     * @return list<RoundThrows>
     */
    public function findByGameIdOrdered(int $gameId): array;

    /**
     * @param int $gameId
     * @param int $roundNumber
     *
     * @return array<int, array{playerId:int,roundNumber:int,throwNumber:int,value:int,isDouble:bool,isTriple:bool,isBust:bool}>
     */
    public function findCurrentRoundThrowsForGamePlayers(int $gameId, int $roundNumber): array;

    /**
     * @param int $gameId
     * @param int $roundNumber
     *
     * @return array<int, array{throwsCount:int,lastThrowNumber:int|null,lastThrowValue:int|null,lastThrowBust:bool}>
     */
    public function findCurrentRoundStateSnapshot(int $gameId, int $roundNumber): array;

    /**
     * @param int $gameId
     *
     * @return array<int, array{playerId:int,roundNumber:int,throwNumber:int,value:int,isDouble:bool,isTriple:bool,isBust:bool}>
     */
    public function findLatestThrowsForGamePlayers(int $gameId): array;

    /**
     * @param int $gameId
     *
     * @return array<int, array{playerId:int,roundNumber:int,throwNumber:int,value:int,isDouble:bool,isTriple:bool,isBust:bool}>
     */
    public function findRoundHistoryForGame(int $gameId): array;

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
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param int $gameId
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRoundAveragesForGame(int $gameId): array;

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param int $gameId
     *
     * @return array<int, int>
     */
    public function getLastRoundNumberForGame(int $gameId): array;

    /**
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
    public function getPlayerStatistics(int $limit, int $offset, string $sortField = 'average', string $direction = 'DESC'): array;

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

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param string      $alias
     * @param string|null $indexBy
     *
     * @return QueryBuilder
     */
    public function createQueryBuilder(string $alias, ?string $indexBy = null): QueryBuilder;
}
