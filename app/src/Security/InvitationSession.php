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
    public const string INVITATION_UUID = 'invitation_uuid';
    public const string GAME_ID = 'game_id';

    /**
     * Returns the invited game id and removes the invitation from the session.
     *
     * Whatever the join outcome, the invitation is spent: a stale one must not block later
     * logins. The session holds an invitation only with both `invitation_uuid` and a usable
     * `game_id`.
     *
     * @param SessionInterface $session
     *
     * @return int|null
     */
    public static function take(SessionInterface $session): ?int
    {
        $hasInvitation = $session->has(self::INVITATION_UUID);
        $gameId = filter_var($session->get(self::GAME_ID), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $session->remove(self::INVITATION_UUID);
        $session->remove(self::GAME_ID);

        return $hasInvitation && false !== $gameId ? $gameId : null;
    }
}
