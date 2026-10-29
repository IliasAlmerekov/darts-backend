<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\User;

/**
 * Contract for game access checks.
 */
interface GameAccessServiceInterface
{
    /**
     * Ensure an authenticated user is present.
     *
     * @return User
     */
    public function requireAuthenticatedUser(): User;

    /**
     * Ensure the current user is admin.
     *
     * @psalm-suppress PossiblyUnusedReturnValue Reason: the interface retains the authenticated user for callers.
     *
     * @return User
     */
    public function assertAdmin(): User;
}
