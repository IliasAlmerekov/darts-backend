<?php

namespace App\Repository;

use App\Entity\RoundThrows;

interface RoundThrowsRepositoryInterface
{
    /**
     * @param array<string, mixed> $criteria
     * @param array<string, string>|null $orderBy
     * @return RoundThrows[]|array<object>
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array;
}
