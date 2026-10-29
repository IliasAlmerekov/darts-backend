<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

use App\Entity\User;

/**
 * Where a successful login sends the player, before it becomes the login success body.
 *
 * At most one of `gameId` and `invitationError` is set.
 */
final readonly class LoginSuccessDto
{
    /**
     * @param User        $user
     * @param string      $frontendPath    path on the frontend the client should open next
     * @param int|null    $gameId          the joined or current game, with `/joined`
     * @param string|null $invitationError why the invitation join failed, with `/playerprofile`
     */
    public function __construct(
        public User $user,
        public string $frontendPath,
        public ?int $gameId = null,
        public ?string $invitationError = null,
    ) {
    }
}
