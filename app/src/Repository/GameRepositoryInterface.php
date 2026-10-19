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
     * Locks the game row and its seats until the transaction ends, then reloads the game and
     * its seat collection. Unlike the entity a request already holds, the reloaded state
     * includes what other transactions committed while this one waited for the lock.
     * Must be called inside an open transaction.
     *
     * @param Game $game
     *
     * @return void
     */
    public function refreshWithSeatsForUpdate(Game $game): void;

    /**
     * Returns the game in the lobby; at most one exists (ADR 0003).
     *
     * @return Game|null
     */
    public function findOpenLobby(): ?Game;

    /**
     * Returns the game in the lobby and acquires a shared lock (FOR SHARE). Unlike a plain
     * read inside a transaction, the locking read also sees rows committed after the
     * transaction's snapshot was taken. Must be called inside a transaction.
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
     * Sets every started game except $exceptGameId to aborted in one bulk update (ADR 0004).
     * The update bypasses the EntityManager, so loaded Game entities keep their old status.
     * Must be called inside the request's transaction.
     *
     * @param int|null $exceptGameId
     *
     * @return void
     */
    public function abortStartedGames(?int $exceptGameId = null): void;

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
