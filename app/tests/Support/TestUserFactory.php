<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists throwaway users for functional tests. The prefix names the calling test, so
 * rows left by different tests stay apart; a random suffix keeps email and username unique.
 */
final class TestUserFactory
{
    /**
     * @param list<string> $roles
     */
    public static function create(EntityManagerInterface $entityManager, string $prefix, string $label, array $roles): User
    {
        $suffix = bin2hex(random_bytes(4));
        $user = (new User())
            ->setEmail(sprintf('%s-%s-%s@test.dev', $prefix, $label, $suffix))
            ->setUsername(sprintf('%s_%s_%s', $prefix, $label, $suffix))
            ->setPassword('unused')
            ->setRoles($roles);
        $entityManager->persist($user);

        return $user;
    }
}
