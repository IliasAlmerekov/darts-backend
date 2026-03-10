<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Game;
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
