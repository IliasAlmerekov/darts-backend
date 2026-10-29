<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The invitation a scanned link stored in the session (InvitationController::joinInvitation).
 */
final class InvitationSession
{
    /**
     * Returns the invited game id and removes the invitation from the session.
     *
     * Whatever the join outcome, the invitation is spent: a stale one must not block later
     * logins. A session with `invitation_uuid` but no usable `game_id` holds no invitation.
     *
     * @param SessionInterface $session
     *
     * @return int|null
     */
    public static function take(SessionInterface $session): ?int
    {
        $gameId = filter_var($session->get('game_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $session->remove('invitation_uuid');
        $session->remove('game_id');

        return false === $gameId ? null : $gameId;
    }
}
