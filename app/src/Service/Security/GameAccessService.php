<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\User;
use App\Exception\Security\SecurityAccessDeniedException;
use App\Exception\Security\UserNotAuthenticatedException;
use Override;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Enforces game-related authorization rules.
 *
 * @psalm-suppress UnusedClass Reason: service is wired via Symfony DI.
 */
final readonly class GameAccessService implements GameAccessServiceInterface
{
    /**
     * @param Security $security
     */
    public function __construct(private Security $security)
    {
    }

    /**
     * @return User
     */
    #[Override]
    public function requireAuthenticatedUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UserNotAuthenticatedException();
        }

        return $user;
    }

    /**
     * @return User
     */
    #[Override]
    public function assertAdmin(): User
    {
        $user = $this->requireAuthenticatedUser();
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new SecurityAccessDeniedException();
        }

        return $user;
    }
}
