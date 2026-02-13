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
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param mixed $id
     *
     * @return User|object|null
     */
    public function find(mixed $id): ?object;

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param array<int> $ids
     *
     * @return User[]
     */
    public function findByIds(array $ids): array;

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param string $username
     *
     * @return User|null
     */
    public function findOneByUsername(string $username): ?User;

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
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
