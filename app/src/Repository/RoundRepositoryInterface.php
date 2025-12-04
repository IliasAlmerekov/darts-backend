<?php

namespace App\Repository;

use App\Entity\Round;

interface RoundRepositoryInterface
{
    /**
     * @param array<string, mixed> $criteria
     * @param array<string, string>|null $orderBy
     * @return Round|object|null
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object;

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, string>|null $orderBy
     * @return Round[]|array<object>
     */
    public function findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null): array;

    public function countFinishedRounds(int $gameId): int;
}
