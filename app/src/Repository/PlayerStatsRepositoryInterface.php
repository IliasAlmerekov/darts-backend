<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlayerStats;

/**
 * Contract for player stats repository.
 */
interface PlayerStatsRepositoryInterface
{
    /**
     * @param mixed $id
     *
     * @return PlayerStats|object|null
     */
    public function find(mixed $id): ?object;
}
