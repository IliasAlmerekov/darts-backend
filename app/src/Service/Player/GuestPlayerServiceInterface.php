<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Player;

use App\Exception\Game\GameNotFoundException;

/**
 * Contract for creating guest players.
 */
interface GuestPlayerServiceInterface
{
    /**
     * @param int    $gameId
     * @param string $username
     *
     * @return array{playerId:int,name:string,position:int|null,placement:int|null,isGuest:bool}
     *
     * @throws GameNotFoundException When no game has this ID
     */
    public function createGuestPlayer(int $gameId, string $username): array;
}
