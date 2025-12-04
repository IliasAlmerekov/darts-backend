<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invitation;

/**
 * Contract for invitation repository.
 */
interface InvitationRepositoryInterface
{
    /**
     * @param mixed $id
     *
     * @return Invitation|object|null
     */
    public function find(mixed $id): ?object;

    /**
     * @param array<string, mixed>       $criteria
     * @param array<string, string>|null $orderBy
     *
     * @return Invitation|object|null
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object;
}
