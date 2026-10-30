<?php

/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\RoundThrows;
use App\Entity\Game;

/** Undoes the latest throw inside the caller's transaction. */
interface UndoThrowServiceInterface
{
    /**
     * @param Game $game
     *
     * @return RoundThrows|null
     */
    public function undo(Game $game): ?RoundThrows;
}
