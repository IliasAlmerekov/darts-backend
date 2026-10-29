<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Repository;

/**
 * The display-name rule of App\Service\Game\PlayerDisplayName as a query expression: the seat
 * snapshot, else the user's display name, else the username, each trimmed of spaces, a blank
 * one counting as missing. Without a seat alias the snapshot is left out, which gives the rule
 * of PlayerDisplayName::forUser(). Change both classes together.
 */
final class PlayerDisplayNameExpression
{
    /**
     * The expression over DQL field names.
     *
     * @param string|null $seatAlias alias of the GamePlayers seat, or null for the user-level rule
     * @param string      $userAlias alias of the User
     *
     * @return string
     */
    public static function dql(?string $seatAlias, string $userAlias): string
    {
        return self::build($seatAlias, $userAlias, 'displayNameSnapshot', 'displayName');
    }

    /**
     * The expression over native column names.
     *
     * @param string|null $seatAlias alias of the game_players row, or null for the user-level rule
     * @param string      $userAlias alias of the user row
     *
     * @return string
     */
    public static function sql(?string $seatAlias, string $userAlias): string
    {
        return self::build($seatAlias, $userAlias, 'display_name_snapshot', 'display_name');
    }

    /**
     * @param string|null $seatAlias
     * @param string      $userAlias
     * @param string      $snapshotField
     * @param string      $displayNameField
     *
     * @return string
     */
    private static function build(?string $seatAlias, string $userAlias, string $snapshotField, string $displayNameField): string
    {
        $parts = [];
        if (null !== $seatAlias) {
            $parts[] = sprintf("NULLIF(TRIM(%s.%s), '')", $seatAlias, $snapshotField);
        }
        $parts[] = sprintf("NULLIF(TRIM(%s.%s), '')", $userAlias, $displayNameField);
        $parts[] = sprintf('TRIM(%s.username)', $userAlias);

        return sprintf('COALESCE(%s)', implode(', ', $parts));
    }
}
