<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invitation;

/**
 * Contract for invitation repository.
 */
/** @psalm-suppress UnusedClass */
interface InvitationRepositoryInterface
{
    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param mixed $id
     *
     * @return Invitation|object|null
     */
    public function find(mixed $id): ?object;

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param array<string, mixed>       $criteria
     * @param array<string, string>|null $orderBy
     *
     * @return Invitation|object|null
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object;

    /**
     * Returns the game's invitation with a shared lock. Unlike a plain read inside a
     * transaction, it sees an invitation another transaction committed after this one's
     * snapshot.
     *
     * @param int $gameId
     *
     * @return Invitation|null
     */
    public function findOneByGameIdForShare(int $gameId): ?Invitation;
}
