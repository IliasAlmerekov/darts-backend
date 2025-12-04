<?php

namespace App\Repository;

use App\Entity\Round;

interface RoundRepositoryInterface
{
    /**
     * @param array<string, mixed> $criteria
     * @param array<string, string>|null $orderBy
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?Round;

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, string>|null $orderBy
     * @return Round[]
     */
    public function findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null): array;

    public function countFinishedRounds(int $gameId): int;
}
