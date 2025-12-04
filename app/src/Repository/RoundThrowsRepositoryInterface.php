<?php

namespace App\Repository;

use App\Entity\RoundThrows;

/**
 * Contract for round throws repository.
 */

interface RoundThrowsRepositoryInterface
{
    /**
     * @param array<string, mixed>       $criteria
     * @param array<string, string>|null $orderBy
     * @param int|null                   $limit
     * @param int|null                   $offset
     *
     * @return RoundThrows[]|array<object>
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array;
}
