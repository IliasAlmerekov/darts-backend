<?php

namespace App\Repository;

use App\Entity\Round;

/**
 * Contract for round repository.
 */

interface RoundRepositoryInterface
{
    /**
     * @param array<string, mixed>       $criteria
     * @param array<string, string>|null $orderBy
     *
     * @return Round|object|null
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object;
/**
     * @param array<string, mixed>       $criteria
     * @param array<string, string>|null $orderBy
     * @param int|null                   $limit
     * @param int|null                   $offset
     *
     * @return Round[]|array<object>
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array;
/**
     * @param int $gameId
     *
     * @return int
     */
    public function countFinishedRounds(int $gameId): int;
}
