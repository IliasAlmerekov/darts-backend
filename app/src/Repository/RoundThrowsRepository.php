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
use Doctrine\DBAL\ParameterType;
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
    #[\Override]
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
    #[\Override]
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
    #[\Override]
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
     * @param int $playerId
     * @param int $throwId
     *
     * @return RoundThrows|null
     */
    #[\Override]
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
    #[\Override]
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
    #[\Override]
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
     * @param int $roundNumber
     *
     * @return array<int, array{throwsCount:int,lastThrowNumber:int|null,lastThrowValue:int|null,lastThrowBust:bool}>
     */
    #[\Override]
    public function findCurrentRoundStateSnapshot(int $gameId, int $roundNumber): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $roundTable = $connection->getDatabasePlatform()->quoteSingleIdentifier('round');

        $rows = $connection->fetchAllAssociative(
            sprintf(
                <<<'SQL'
SELECT
    round_state.playerId AS playerId,
    round_state.throwsCount AS throwsCount,
    latest.throw_number AS lastThrowNumber,
    latest.value AS lastThrowValue,
    latest.is_bust AS lastThrowBust
FROM (
    SELECT
        rt.player_id AS playerId,
        COUNT(rt.throw_id) AS throwsCount,
        MAX(rt.throw_id) AS lastThrowId
    FROM round_throws rt
    INNER JOIN %s r ON r.round_id = rt.round_id
    WHERE rt.game_id = :gameId
      AND r.round_number = :roundNumber
    GROUP BY rt.player_id
) round_state
INNER JOIN round_throws latest ON latest.throw_id = round_state.lastThrowId
ORDER BY round_state.playerId ASC
SQL,
                $roundTable,
            ),
            [
                'gameId' => $gameId,
                'roundNumber' => $roundNumber,
            ],
            [
                'gameId' => ParameterType::INTEGER,
                'roundNumber' => ParameterType::INTEGER,
            ],
        );

        return $this->normalizeRoundStateSnapshotRows($rows);
    }

    /**
     * @param int $gameId
     *
     * @return array<int, array{playerId:int,roundNumber:int,throwNumber:int,value:int,isDouble:bool,isTriple:bool,isBust:bool}>
     */
    #[\Override]
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
    #[\Override]
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
    #[\Override]
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
    #[\Override]
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
    #[\Override]
    public function getTotalScoreForGame(int $gameId): array
    {
        $rows = $this->createQueryBuilder('rt')
            ->select(
                'IDENTITY(rt.player) AS playerId',
                "SUM(CASE WHEN rt.isBust = TRUE THEN 0 ELSE rt.value END) AS totalValue"
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
    #[\Override]
    public function getPlayerStatistics(int $limit, int $offset, string $sortField = 'average', string $direction = 'DESC'): array
    {
        $orderColumn = 'gamesPlayed' === $sortField ? 'gamesPlayed' : 'scoreAverage';
        $direction = 'ASC' === strtoupper($direction) ? 'ASC' : 'DESC';
        $connection = $this->getEntityManager()->getConnection();
        $roundTable = $connection->getDatabasePlatform()->quoteSingleIdentifier('round');
        $userTable = $connection->getDatabasePlatform()->quoteSingleIdentifier('user');
        $sql = sprintf(
            <<<'SQL'
SELECT
    player_rounds.playerId AS playerId,
    player_rounds.username AS username,
    COUNT(DISTINCT player_rounds.gameId) AS gamesPlayed,
    SUM(player_rounds.roundTotal) AS totalValue,
    COUNT(*) AS roundsFinished,
    (
        SUM(player_rounds.roundTotal) /
        NULLIF(COUNT(*), 0)
    ) AS scoreAverage
FROM (
    SELECT
        rt.player_id AS playerId,
        u.display_name AS username,
        rt.game_id AS gameId,
        rt.round_id AS roundId,
        SUM(CASE WHEN rt.is_bust = TRUE THEN 0 ELSE rt.value END) AS roundTotal
    FROM round_throws rt
    INNER JOIN game g ON g.game_id = rt.game_id
    INNER JOIN %s r ON r.round_id = rt.round_id
    INNER JOIN %s u ON u.id = rt.player_id
    WHERE g.status = :status
      AND u.is_guest = FALSE
      AND r.finished_at IS NOT NULL
    GROUP BY rt.player_id, u.display_name, rt.game_id, rt.round_id
) player_rounds
GROUP BY player_rounds.playerId, player_rounds.username
ORDER BY %s %s
LIMIT :limit OFFSET :offset
SQL,
            $roundTable,
            $userTable,
            $orderColumn,
            $direction,
        );

        /** @var array<int, array{
         *     playerId:int,
         *     username:string,
         *     gamesPlayed:string,
         *     totalValue:string,
         *     roundsFinished:string,
         *     scoreAverage:string|null
         * }> $rows
         */
        $rows = $connection->fetchAllAssociative(
            $sql,
            [
                'status' => GameStatus::Finished->value,
                'limit' => $limit,
                'offset' => $offset,
            ],
            [
                'status' => ParameterType::STRING,
                'limit' => ParameterType::INTEGER,
                'offset' => ParameterType::INTEGER,
            ],
        );

        return $rows;
    }

    /**
     * Counts distinct players who have finished rounds in finished games.
     *
     * @return int
     */
    #[\Override]
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

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array{throwsCount:int,lastThrowNumber:int|null,lastThrowValue:int|null,lastThrowBust:bool}>
     */
    private function normalizeRoundStateSnapshotRows(array $rows): array
    {
        $snapshot = [];

        foreach ($rows as $row) {
            $playerId = (int) ($this->resolveSnapshotRowValue($row, 'playerId') ?? 0);
            if ($playerId <= 0) {
                continue;
            }

            $lastThrowNumber = $this->resolveSnapshotRowValue($row, 'lastThrowNumber');
            $lastThrowValue = $this->resolveSnapshotRowValue($row, 'lastThrowValue');

            $snapshot[$playerId] = [
                'throwsCount' => (int) ($this->resolveSnapshotRowValue($row, 'throwsCount') ?? 0),
                'lastThrowNumber' => null !== $lastThrowNumber ? (int) $lastThrowNumber : null,
                'lastThrowValue' => null !== $lastThrowValue ? (int) $lastThrowValue : null,
                'lastThrowBust' => (bool) ($this->resolveSnapshotRowValue($row, 'lastThrowBust') ?? false),
            ];
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $row
     * @param string               $key
     *
     * @return mixed
     */
    private function resolveSnapshotRowValue(array $row, string $key): mixed
    {
        $snakeCaseKey = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
        $candidates = [
            $key,
            strtolower($key),
            $snakeCaseKey,
            str_replace('_', '', $snakeCaseKey),
        ];

        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $row)) {
                return $row[$candidate];
            }
        }

        return null;
    }
}
