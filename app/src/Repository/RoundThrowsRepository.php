<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Repository;

use App\Dto\ParticipantTotalsDto;
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
                "COALESCE(NULLIF(TRIM(gp.displayNameSnapshot), ''), NULLIF(TRIM(u.displayName), ''), TRIM(u.username)) AS playerName"
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
     * getLastRoundNumberForGame(), getRoundsPlayedForGame() and getTotalScoreForGame() for
     * several games in one query.
     *
     * @param list<int> $gameIds
     *
     * @return array<int, array<int, ParticipantTotalsDto>> per game id, then player id
     */
    #[\Override]
    public function getParticipantTotalsForGames(array $gameIds): array
    {
        if ([] === $gameIds) {
            return [];
        }

        /** @var list<array{gameId:int|string,playerId:int|string,lastRound:int|string,roundsPlayed:int|string,totalValue:int|string|null}> $rows */
        $rows = $this->createQueryBuilder('rt')
            ->select(
                'IDENTITY(rt.game) AS gameId',
                'IDENTITY(rt.player) AS playerId',
                'MAX(r.roundNumber) AS lastRound',
                'COUNT(DISTINCT r.roundNumber) AS roundsPlayed',
                'SUM(CASE WHEN rt.isBust = TRUE THEN 0 ELSE rt.value END) AS totalValue'
            )
            ->innerJoin('rt.round', 'r')
            ->andWhere('rt.game IN (:gameIds)')
            ->setParameter('gameIds', $gameIds)
            ->groupBy('gameId', 'playerId')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['gameId']][(int) $row['playerId']] = new ParticipantTotalsDto(
                lastRound: (int) $row['lastRound'],
                roundsPlayed: (int) $row['roundsPlayed'],
                totalScore: (float) $row['totalValue'],
            );
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
        return $this->fetchPlayerStatistics(null, $limit, $offset, $sortField, $direction);
    }

    /**
     * The getPlayerStatistics() row of one player, or null when the player has no finished
     * round in a finished game.
     *
     * @param int $playerId
     *
     * @return array{
     *     playerId:int,
     *     username:string,
     *     gamesPlayed:string,
     *     totalValue:string,
     *     roundsFinished:string,
     *     scoreAverage:string|null
     * }|null
     */
    #[\Override]
    public function getPlayerStatisticsForPlayer(int $playerId): ?array
    {
        return $this->fetchPlayerStatistics($playerId)[0] ?? null;
    }

    /**
     * Every throw of the player, oldest first, in the games getPlayerStatisticsForPlayer()
     * counts: finished games in which the player has a finished round. Each row carries its
     * game's start score, out rule and the player's placement in that game.
     *
     * shortcut: loads every throw of the player on each Profile read, so the payload grows with
     * the games played; aggregate in SQL or materialise the statistics once a Profile read gets slow.
     *
     * @param int $playerId
     *
     * @return list<array{
     *     gameId:int,
     *     roundId:int,
     *     value:int,
     *     isDouble:bool,
     *     isTriple:bool,
     *     isBust:bool,
     *     score:int,
     *     startScore:int,
     *     doubleOut:bool,
     *     tripleOut:bool,
     *     placement:int|null
     * }>
     */
    #[\Override]
    public function findThrowsInCountedGamesForPlayer(int $playerId): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $roundTable = $connection->getDatabasePlatform()->quoteSingleIdentifier('round');

        $rows = $connection->fetchAllAssociative(
            sprintf(
                <<<'SQL'
SELECT
    rt.game_id AS gameId,
    rt.round_id AS roundId,
    rt.value AS value,
    rt.is_double AS isDouble,
    rt.is_triple AS isTriple,
    rt.is_bust AS isBust,
    rt.score AS score,
    g.start_score AS startScore,
    g.double_out AS doubleOut,
    g.triple_out AS tripleOut,
    gp.placement AS placement
FROM round_throws rt
INNER JOIN game g ON g.game_id = rt.game_id
LEFT JOIN game_players gp ON gp.game_id = rt.game_id AND gp.player_id = rt.player_id
WHERE rt.player_id = :playerId
  AND g.status = :status
  AND EXISTS (
      SELECT 1
      FROM round_throws finished_throw
      INNER JOIN %s r ON r.round_id = finished_throw.round_id
      WHERE finished_throw.game_id = rt.game_id
        AND finished_throw.player_id = rt.player_id
        AND r.finished_at IS NOT NULL
  )
ORDER BY rt.throw_id ASC
SQL,
                $roundTable,
            ),
            ['playerId' => $playerId, 'status' => GameStatus::Finished->value],
            ['playerId' => ParameterType::INTEGER, 'status' => ParameterType::STRING],
        );

        return array_map(static fn(array $row): array => [
            'gameId' => (int) $row['gameId'],
            'roundId' => (int) $row['roundId'],
            'value' => (int) $row['value'],
            'isDouble' => (bool) $row['isDouble'],
            'isTriple' => (bool) $row['isTriple'],
            'isBust' => (bool) $row['isBust'],
            'score' => (int) $row['score'],
            'startScore' => (int) $row['startScore'],
            'doubleOut' => (bool) $row['doubleOut'],
            'tripleOut' => (bool) $row['tripleOut'],
            'placement' => null !== $row['placement'] ? (int) $row['placement'] : null,
        ], $rows);
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
     * Aggregates finished rounds of finished games per registered player. Both public
     * statistics reads share this query, so the admin list and a player's Profile agree.
     *
     * @param int|null $playerId  only this player's row, or every player's
     * @param int|null $limit     page size, or every row
     * @param int      $offset
     * @param string   $sortField 'gamesPlayed', or the score average
     * @param string   $direction
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
    private function fetchPlayerStatistics(
        ?int $playerId,
        ?int $limit = null,
        int $offset = 0,
        string $sortField = 'average',
        string $direction = 'DESC',
    ): array {
        $orderColumn = 'gamesPlayed' === $sortField ? 'gamesPlayed' : 'scoreAverage';
        $direction = 'ASC' === strtoupper($direction) ? 'ASC' : 'DESC';
        $parameters = ['status' => GameStatus::Finished->value];
        $types = ['status' => ParameterType::STRING];
        $playerFilter = '';
        if (null !== $playerId) {
            $playerFilter = 'AND rt.player_id = :playerId';
            $parameters['playerId'] = $playerId;
            $types['playerId'] = ParameterType::INTEGER;
        }
        $page = '';
        if (null !== $limit) {
            $page = 'LIMIT :limit OFFSET :offset';
            $parameters += ['limit' => $limit, 'offset' => $offset];
            $types += ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER];
        }

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
      %s
    GROUP BY rt.player_id, u.display_name, rt.game_id, rt.round_id
) player_rounds
GROUP BY player_rounds.playerId, player_rounds.username
ORDER BY %s %s
%s
SQL,
            $roundTable,
            $userTable,
            $playerFilter,
            $orderColumn,
            $direction,
            $page,
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
        $rows = $connection->fetchAllAssociative($sql, $parameters, $types);

        return $rows;
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
