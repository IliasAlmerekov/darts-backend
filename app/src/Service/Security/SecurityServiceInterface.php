<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Dto\LoginSuccessDto;
use App\Entity\User;

/**
 * Contract for the outcome of a successful login.
 */
interface SecurityServiceInterface
{
    /**
     * Decides where a logged-in user goes next, joining the invited game first.
     *
     * @param User     $user
     * @param int|null $invitedGameId the game of the invitation the session held, if any
     *
     * @return LoginSuccessDto
     */
    public function resolveLoginSuccess(User $user, ?int $invitedGameId): LoginSuccessDto;
}
