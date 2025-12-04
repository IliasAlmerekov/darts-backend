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
     * @param mixed             $id
     * @param LockMode|int|null $lockMode
     * @param int|null          $lockVersion
     *
     * @return Game|object|null
     */
    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object;

    /**
     * @param string      $alias
     * @param string|null $indexBy
     *
     * @return QueryBuilder
     */
    public function createQueryBuilder(string $alias, ?string $indexBy = null): QueryBuilder;

    /**
     * @param int $gameId
     *
     * @return Game|null
     */
    public function findOneByGameId(int $gameId): ?Game;

    /**
     * @return int|null
     */
    public function findHighestGameId(): ?int;

    /**
     * @return int
     */
    public function countFinishedGames(): int;
}
