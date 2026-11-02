<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\GamePlayers;
use App\Entity\User;
use App\Service\Game\PlayerDisplayName;

/**
 * Builds a seat with the display-name snapshot the seat-creation use case gives it, so tests can
 * persist seats without going through PlayerManagementService.
 */
final class TestSeatFactory
{
    public static function create(User $player): GamePlayers
    {
        return (new GamePlayers())->setPlayer($player)->setDisplayNameSnapshot(PlayerDisplayName::forUser($player));
    }
}
