<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

/**
 * Finds the one game in the lobby, or opens it.
 */
interface OpenLobbyServiceInterface
{
    /**
     * Returns the game in the lobby. Creates it when no game is in the lobby, and returns the
     * other request's lobby when that request created one first.
     *
     * @return OpenLobby
     */
    public function findOrCreate(): OpenLobby;
}
