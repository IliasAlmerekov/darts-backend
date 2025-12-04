<?php

namespace App\Repository;

use App\Enum\GameStatus;
use App\Entity\RoundThrows;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RoundThrows>
 */
final class RoundThrowsRepository extends ServiceEntityRepository
{
    /**
     * @param ManagerRegistry $registry
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
                'u.username AS playerName'
            )
            ->innerJoin('rt.round', 'r')
            ->innerJoin('rt.player', 'u')
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
    ): array {
        $orderColumn = 'gamesPlayed' === $sortField ? 'gamesPlayed' : 'scoreAverage';
        $direction = 'ASC' === strtoupper($direction) ? 'ASC' : 'DESC';

        return $this->createQueryBuilder('rt')
            ->select(
                'u.id AS playerId',
                'u.username AS username',
                'COUNT(DISTINCT g.gameId) AS gamesPlayed',
                "SUM(CASE WHEN rt.isBust = true THEN 0 ELSE rt.value END) AS totalValue",
                'COUNT(DISTINCT r.roundId) AS roundsFinished',
                "(SUM(CASE WHEN rt.isBust = true THEN 0 ELSE rt.value END) / "
                . "NULLIF(COUNT(DISTINCT r.roundId), 0)) AS scoreAverage"
            )
            ->innerJoin('rt.player', 'u')
            ->innerJoin('rt.game', 'g')
            ->innerJoin('rt.round', 'r')
            ->andWhere('g.status = :status')
            ->andWhere('r.finishedAt IS NOT NULL')
            ->setParameter('status', GameStatus::Finished)
            ->groupBy('u.id', 'u.username')
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
            ->andWhere('r.finishedAt IS NOT NULL')
            ->setParameter('status', GameStatus::Finished)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
