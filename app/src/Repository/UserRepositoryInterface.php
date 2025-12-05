<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * Contract for user repository.
 */
interface UserRepositoryInterface extends PasswordUpgraderInterface
{
    /**
     * @param mixed $id
     *
     * @return User|object|null
     */
    /** @psalm-suppress PossiblyUnusedMethod */
    public function find(mixed $id): ?object;

    /**
     * @param array<int> $ids
     *
     * @return User[]
     */
    /** @psalm-suppress PossiblyUnusedMethod */
    public function findByIds(array $ids): array;

    /**
     * @param array<string, mixed>       $criteria
     * @param array<string, string>|null $orderBy
     * @param int|null                   $limit
     * @param int|null                   $offset
     *
     * @return User[]|array<object>
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array;

    /**
     * @param PasswordAuthenticatedUserInterface $user
     * @param string                             $newHashedPassword
     *
     * @return void
     */
    #[\Override]
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void;
}
