<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Decides who gets a remember-me cookie. The admin and the players share one firewall, so
 * the role is the only thing that tells the shared tablet (ADR 0001) from a player's phone
 * (ADR 0002). Programmatic logins reuse this check.
 */
final class RememberedLoginPolicy
{
    /**
     * @param UserInterface $user
     *
     * @return bool
     */
    public function isRemembered(UserInterface $user): bool
    {
        $roles = $user->getRoles();

        return in_array('ROLE_PLAYER', $roles, true) && !in_array('ROLE_ADMIN', $roles, true);
    }
}
