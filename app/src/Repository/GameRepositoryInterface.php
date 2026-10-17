<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Game;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\QueryBuilder;

/**
 * Contract for game repository.
 */
interface GameRepositoryInterface
{
    /**
        * @param int $limit
        * @param int $offset
        *
     * @return list<array{
     *     id:int,
     *     date:string|null,
     *     finishedAt:string|null,
     *     playersCount:int,
     *     winnerName:string|null,
     *     winnerId:int|null,
     *     winnerRounds:int
     * }>
     */
    public function findFinishedOverview(int $limit, int $offset): array;

    /**
     * @param mixed             $id
     * @param LockMode|int|null $lockMode
     * @param int|null          $lockVersion
     *
     * @return object|null
     */
    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object;

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param string      $alias
     * @param string|null $indexBy
     *
     * @return QueryBuilder
     */
    public function createQueryBuilder(string $alias, ?string $indexBy = null): QueryBuilder;

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param int $gameId
     *
     * @return Game|null
     */
    public function findOneByGameId(int $gameId): ?Game;

    /**
     * Loads a game by id and acquires a pessimistic write lock.
     * Must be called inside an open transaction.
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param int $gameId
     *
     * @return Game|null
     */
    public function findOneByGameIdForUpdate(int $gameId): ?Game;

    /**
     * Returns the game in the lobby; at most one exists (ADR 0003).
     *
     * @return Game|null
     */
    public function findOpenLobby(): ?Game;

    /**
     * Returns the game in the lobby and acquires a shared lock (FOR SHARE). Unlike a plain
     * read inside a transaction, the locking read also sees rows committed after the
     * transaction's snapshot was taken. A shared lock, unlike FOR UPDATE, does not deadlock
     * with other requests that hold a shared lock on the same index record after their
     * insert was rejected as a duplicate.
     *
     * @return Game|null
     */
    public function findOpenLobbyForShare(): ?Game;

    /**
     * Inserts a game in the lobby through DBAL and returns its id. The insert bypasses the
     * EntityManager, because a failed flush would close it, and callers still need it after
     * the open lobby index rejects the insert.
     *
     * @return int
     *
     * @throws UniqueConstraintViolationException When a game is already in the lobby
     */
    public function insertLobby(): int;

    /**
     * @return int|null
     */
    /** @psalm-suppress PossiblyUnusedMethod */
    public function findHighestGameId(): ?int;

    /**
     * @return int
     */
    public function countFinishedGames(): int;

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param int $limit
     * @param int $offset
     *
     * @return Game[]
     */
    public function findFinished(int $limit, int $offset): array;
}
