<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\GamePlayers;
use App\Entity\User;

/**
 * The display name of a participant: the seat snapshot, else the user's display name, else the
 * username. Each value is trimmed of spaces and a blank one counts as missing. Only spaces are
 * trimmed, as SQL TRIM() does, so the SQL name expressions in the repositories compute the same rule.
 */
final class PlayerDisplayName
{
    /**
     * @param GamePlayers $seat
     *
     * @return string
     */
    public static function forSeat(GamePlayers $seat): string
    {
        $snapshot = trim($seat->getDisplayNameSnapshot() ?? '', ' ');
        if ('' !== $snapshot) {
            return $snapshot;
        }

        $user = $seat->getPlayer();

        return null === $user ? '' : self::forUser($user);
    }

    /**
     * The name a new seat takes as its snapshot.
     *
     * @param User $user
     *
     * @return string
     */
    public static function forUser(User $user): string
    {
        $displayName = trim($user->getDisplayNameRaw() ?? '', ' ');
        if ('' !== $displayName) {
            return $displayName;
        }

        return trim($user->getUsername() ?? '', ' ');
    }
}
