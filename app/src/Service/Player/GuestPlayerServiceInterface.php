<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Player;

use App\Entity\Game;

/**
 * Contract for creating guest players.
 */
interface GuestPlayerServiceInterface
{
    /**
     * @param Game   $game
     * @param string $username
     *
     * @return array{playerId:int,name:string,position:int|null,isGuest:bool}
     */
    public function createGuestPlayer(Game $game, string $username): array;
}
