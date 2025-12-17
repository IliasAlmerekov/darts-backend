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
 * Interface for the GameAbortService.
 */
interface GameAbortServiceInterface
{
    /**
     * Aborts a game by setting its status to aborted.
     *
     * @param Game $game
     *
     * @return void
     */
    public function abortGame(Game $game): void;
}
