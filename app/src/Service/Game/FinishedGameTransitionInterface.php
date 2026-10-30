<?php

/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;

/** Transitions a finished game inside the caller's transaction. */
interface FinishedGameTransitionInterface
{
    /**
     * @param Game $game
     *
     * @return void
     */
    public function assertCanReopen(Game $game): void;

    /**
     * @param Game $game
     *
     * @return void
     */
    public function reopenFinished(Game $game): void;
}
