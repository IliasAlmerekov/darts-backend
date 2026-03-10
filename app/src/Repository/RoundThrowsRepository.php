<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Repository;

use App\Enum\GameStatus;
use App\Entity\Round;
use App\Entity\GamePlayers;
use App\Entity\RoundThrows;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RoundThrows>
 */
final class RoundThrowsRepository extends ServiceEntityRepository implements RoundThrowsRepositoryInterface
{
    /**
     * @param ManagerRegistry $registry
     *
     * @psalm-suppress PossiblyUnusedMethod
     * @psalm-suppress UnusedParam
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoundThrows::class);
    }

    /**
     * Latest throw for game (scalar data)
     *
     * @param int $gameId
     *
     * @return array<string, mixed>|null
     */
    public function findLatestForGame(int $gameId): ?array
    {
        return $this->createQueryBuilder('rt')
            ->select(
                'rt.throwId AS id',
                'rt.throwNumber AS throwNumber',
                'rt.value AS value',
                'rt.isDouble AS isDouble',
                'rt.isTriple AS isTriple',
                'rt.isBust AS isBust',
                'rt.score AS score',
                'rt.timestamp AS timestamp',
                'r.roundNumber AS roundNumber',
                'u.id AS playerId',
                "CASE
                    WHEN gp.displayNameSnapshot IS NOT NULL AND gp.displayNameSnapshot <> '' THEN gp.displayNameSnapshot
                    WHEN u.displayName IS NOT NULL AND u.displayName <> '' THEN u.displayName
                    ELSE u.username
                END AS playerName"
            )
            ->innerJoin('rt.round', 'r')
            ->innerJoin('rt.player', 'u')
            ->leftJoin(
                GamePlayers::class,
                'gp',
                'WITH',
                'gp.game = rt.game AND gp.player = rt.player'
            )
            ->andWhere('rt.game = :gameId')
            ->setParameter('gameId', $gameId)
            ->orderBy('rt.throwId', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param int $gameId
     *
     * @return RoundThrows|null
     */
    public function findEntityLatestForGame(int $gameId): ?RoundThrows
    {
        return $this->createQueryBuilder('rt')
            ->andWhere('rt.game = :gameId')
            ->setParameter('gameId', $gameId)
            ->orderBy('rt.throwId', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param int $gameId
     * @param int $playerId
     *
     * @return RoundThrows|null
     */
    public function findLatestForGameAndPlayer(int $gameId, int $playerId): ?RoundThrows
    {
        return $this->createQueryBuilder('rt')
            ->andWhere('rt.game = :gameId')
            ->andWhere('rt.player = :playerId')
            ->setParameter('gameId', $gameId)
            ->setParameter('playerId', $playerId)
            ->orderBy('rt.throwId', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param int $gameId
     * @param int $throwId
     *
     * @return RoundThrows|null
     */
    public function findLatestForGameBeforeThrow(int $gameId, int $throwId): ?RoundThrows
    {
        return $this->createLatestBeforeThrowQueryBuilder($gameId, $throwId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param int $gameId
     * @param int $playerId
     * @param int $throwId
     *
     * @return RoundThrows|null
     */
    public function findLatestForGameAndPlayerBeforeThrow(int $gameId, int $playerId, int $throwId): ?RoundThrows
    {
        return $this->createLatestBeforeThrowQueryBuilder($gameId, $throwId)
            ->andWhere('rt.player = :playerId')
            ->setParameter('playerId', $playerId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param int $gameId
     *
     * @return list<RoundThrows>
     */
    public function findByGameIdOrdered(int $gameId): array
    {
        /** @var list<RoundThrows> $throws */
        $throws = $this->createQueryBuilder('rt')
            ->addSelect('r', 'u')
            ->innerJoin('rt.round', 'r')
            ->innerJoin('rt.player', 'u')
            ->andWhere('rt.game = :gameId')
            ->setParameter('gameId', $gameId)
            ->orderBy('r.roundNumber', 'ASC')
            ->addOrderBy('u.id', 'ASC')
            ->addOrderBy('rt.throwNumber', 'ASC')
            ->addOrderBy('rt.throwId', 'ASC')
            ->getQuery()
            ->getResult();

        return $throws;
    }

    /**
     * @param int $gameId
     * @param int $roundNumber
     *
     * @return array<int, array{playerId:int,roundNumber:int,throwNumber:int,value:int,isDouble:bool,isTriple:bool,isBust:bool}>
     */
    public function findCurrentRoundThrowsForGamePlayers(int $gameId, int $roundNumber): array
    {
        $rows = $this->createGameStateThrowQueryBuilder()
            ->andWhere('rt.game = :gameId')
            ->andWhere('r.roundNumber = :roundNumber')
            ->setParameter('gameId', $gameId)
            ->setParameter('roundNumber', $roundNumber)
            ->orderBy('rt.player', 'ASC')
            ->addOrderBy('rt.throwNumber', 'ASC')
            ->addOrderBy('rt.throwId', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $this->normalizeGameStateThrowRows($rows);
    }

    /**
     * @param int $gameId
     *
     * @return array<int, array{playerId:int,roundNumber:int,throwNumber:int,value:int,isDouble:bool,isTriple:bool,isBust:bool}>
     */
    public function findLatestThrowsForGamePlayers(int $gameId): array
    {
        $rows = $this->createGameStateThrowQueryBuilder()
            ->andWhere('rt.game = :gameId')
            ->andWhere('rt.throwId IN (
                SELECT MAX(rtLatest.throwId)
                FROM App\\Entity\\RoundThrows rtLatest
                WHERE rtLatest.game = :gameId
                GROUP BY rtLatest.player
            )')
            ->setParameter('gameId', $gameId)
            ->orderBy('rt.player', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $this->normalizeGameStateThrowRows($rows);
    }

    /**
     * @param int $gameId
     *
     * @return array<int, array{playerId:int,roundNumber:int,throwNumber:int,value:int,isDouble:bool,isTriple:bool,isBust:bool}>
     */
    public function findRoundHistoryForGame(int $gameId): array
    {
        $rows = $this->createGameStateThrowQueryBuilder()
            ->andWhere('rt.game = :gameId')
            ->setParameter('gameId', $gameId)
            ->orderBy('r.roundNumber', 'ASC')
            ->addOrderBy('rt.player', 'ASC')
            ->addOrderBy('rt.throwNumber', 'ASC')
            ->addOrderBy('rt.throwId', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $this->normalizeGameStateThrowRows($rows);
    }

    /**
     * Returns average throw value per round for each player in a game.
     *
     * @param int $gameId
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRoundAveragesForGame(int $gameId): array
    {
        return $this->createQueryBuilder('rt')
            ->select(
                'IDENTITY(rt.player) AS playerId',
                'r.roundNumber AS roundNumber',
                'AVG(rt.value) AS average'
            )
            ->innerJoin('rt.round', 'r')
            ->andWhere('IDENTITY(rt.game) = :gameId')
            ->setParameter('gameId', $gameId)
            ->groupBy('playerId', 'r.roundNumber')
            ->orderBy('r.roundNumber', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Counts how many distinct rounds each player has played in a game.
     *
     * @param int $gameId
     *
     * @return array<int, int>
     */
    public function getRoundsPlayedForGame(int $gameId): array
    {
        $rows = $this->createQueryBuilder('rt')
            ->select('IDENTITY(rt.player) AS playerId', 'COUNT(DISTINCT r.roundNumber) AS roundsPlayed')
            ->innerJoin('rt.round', 'r')
            ->andWhere('IDENTITY(rt.game) = :gameId')
            ->setParameter('gameId', $gameId)
            ->groupBy('playerId')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['playerId']] = (int) $row['roundsPlayed'];
        }

        return $map;
    }

    /**
     * Returns the last round number each player participated in for a game.
     *
     * @param int $gameId
     *
     * @return array<int,int>
     */
    public function getLastRoundNumberForGame(int $gameId): array
    {
        $rows = $this->createQueryBuilder('rt')
            ->select('IDENTITY(rt.player) AS playerId', 'MAX(r.roundNumber) AS lastRound')
            ->innerJoin('rt.round', 'r')
            ->andWhere('IDENTITY(rt.game) = :gameId')
            ->setParameter('gameId', $gameId)
            ->groupBy('playerId')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['playerId']] = (int) $row['lastRound'];
        }

        return $map;
    }

    /**
     * Sum of thrown values per player in a game.
     *
     * @param int $gameId
     *
     * @return array<int, float>
     */
    public function getTotalScoreForGame(int $gameId): array
    {
        $rows = $this->createQueryBuilder('rt')
            ->select(
                'IDENTITY(rt.player) AS playerId',
                "SUM(CASE WHEN rt.isBust = true THEN 0 ELSE rt.value END) AS totalValue"
            )
            ->andWhere('IDENTITY(rt.game) = :gameId')
            ->setParameter('gameId', $gameId)
            ->groupBy('playerId')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['playerId']] = (float) $row['totalValue'];
        }

        return $map;
    }
    /**
     * Aggregated player statistics over finished games and finished rounds.
     *
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
    public function getPlayerStatistics(int $limit, int $offset, string $sortField = 'average', string $direction = 'DESC'): array
    {
        $orderColumn = 'gamesPlayed' === $sortField ? 'gamesPlayed' : 'scoreAverage';
        $direction = 'ASC' === strtoupper($direction) ? 'ASC' : 'DESC';

        return $this->createQueryBuilder('rt')
            ->select(
                'u.id AS playerId',
                'u.displayName AS username',
                'COUNT(DISTINCT g.gameId) AS gamesPlayed',
                "SUM(CASE WHEN rt.isBust = true THEN 0 ELSE rt.value END) AS totalValue",
                'COUNT(DISTINCT r.roundId) AS roundsFinished',
                "(SUM(CASE WHEN rt.isBust = true THEN 0 ELSE rt.value END) / "."NULLIF(COUNT(DISTINCT r.roundId), 0)) AS scoreAverage"
            )
            ->innerJoin('rt.player', 'u')
            ->innerJoin('rt.game', 'g')
            ->innerJoin('rt.round', 'r')
            ->andWhere('g.status = :status')
            ->andWhere('u.isGuest = false')
            ->andWhere('r.finishedAt IS NOT NULL')
            ->setParameter('status', GameStatus::Finished)
            ->groupBy('u.id', 'u.displayName')
            ->orderBy($orderColumn, $direction)
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Counts distinct players who have finished rounds in finished games.
     *
     * @return int
     */
    public function countPlayersWithFinishedRounds(): int
    {
        return (int) $this->createQueryBuilder('rt')
            ->select('COUNT(DISTINCT u.id)')
            ->innerJoin('rt.player', 'u')
            ->innerJoin('rt.game', 'g')
            ->innerJoin('rt.round', 'r')
            ->andWhere('g.status = :status')
            ->andWhere('u.isGuest = false')
            ->andWhere('r.finishedAt IS NOT NULL')
            ->setParameter('status', GameStatus::Finished)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return QueryBuilder
     */
    private function createGameStateThrowQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('rt')
            ->select(
                'IDENTITY(rt.player) AS playerId',
                'r.roundNumber AS roundNumber',
                'rt.throwNumber AS throwNumber',
                'rt.value AS value',
                'rt.isDouble AS isDouble',
                'rt.isTriple AS isTriple',
                'rt.isBust AS isBust'
            )
            ->innerJoin('rt.round', 'r');
    }

    /**
     * @param int $gameId
     * @param int $throwId
     *
     * @return QueryBuilder
     */
    private function createLatestBeforeThrowQueryBuilder(int $gameId, int $throwId): QueryBuilder
    {
        return $this->createQueryBuilder('rt')
            ->andWhere('rt.game = :gameId')
            ->andWhere('rt.throwId < :throwId')
            ->setParameter('gameId', $gameId)
            ->setParameter('throwId', $throwId)
            ->orderBy('rt.throwId', 'DESC')
            ->setMaxResults(1);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array{playerId:int,roundNumber:int,throwNumber:int,value:int,isDouble:bool,isTriple:bool,isBust:bool}>
     */
    private function normalizeGameStateThrowRows(array $rows): array
    {
        return array_map(static function (array $row): array {
            return [
                'playerId' => (int) $row['playerId'],
                'roundNumber' => (int) $row['roundNumber'],
                'throwNumber' => (int) $row['throwNumber'],
                'value' => (int) $row['value'],
                'isDouble' => isset($row['isDouble']) ? (bool) $row['isDouble'] : false,
                'isTriple' => isset($row['isTriple']) ? (bool) $row['isTriple'] : false,
                'isBust' => isset($row['isBust']) ? (bool) $row['isBust'] : false,
            ];
        }, $rows);
    }
}
