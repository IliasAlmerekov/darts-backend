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
    public function find(mixed $id): ?object;

    /**
     * @param array<int> $ids
     *
     * @return User[]
     */
    public function findByIds(array $ids): array;

    /**
     * @param PasswordAuthenticatedUserInterface $user
     * @param string                             $newHashedPassword
     *
     * @return void
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void;
}
