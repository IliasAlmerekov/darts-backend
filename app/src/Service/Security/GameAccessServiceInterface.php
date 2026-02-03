<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\Game;
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
     * @psalm-suppress PossiblyUnusedMethod Reason: reserved for admin-only flows.
     *
     * @return User
     */
    public function assertAdmin(): User;

    /**
     * Ensure the current user is a player in the game or admin.
     *
     * @param Game $game
     *
     * @return User
     */
    public function assertPlayerInGameOrAdmin(Game $game): User;

    /**
     * Ensure the current user is the given player or admin.
     *
     * @param User $user
     * @param int  $playerId
     *
     * @return void
     */
    public function assertPlayerMatches(User $user, int $playerId): void;
}
