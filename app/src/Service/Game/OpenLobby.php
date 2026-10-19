<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;

/**
 * The game in the lobby, and whether this request created it.
 */
final readonly class OpenLobby
{
    /**
     * @param Game $game
     * @param bool $created
     */
    public function __construct(public Game $game, public bool $created)
    {
    }
}
