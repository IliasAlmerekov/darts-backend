<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\RematchResponseDto;
use App\Exception\Game\GameNotFoundException;
use Doctrine\ORM\Exception\ORMException;

/**
 * Interface for rematch service operations.
 */
interface RematchServiceInterface
{
    /**
     * Create a rematch from an existing game.
     * Returns the open lobby, or a new one, with the old game's players and settings.
     * Opens its own transaction through LockedGameTransactionInterface::runWithRetry(): the
     * lobby row stays locked until it ends, so concurrent rematches into the same lobby cannot
     * add a participant twice.
     *
     * @param int $oldGameId The ID of the game to rematch
     *
     * @return RematchResponseDto The lobby game id and the URL to join it
     *
     * @throws GameNotFoundException When the old game does not exist
     * @throws ORMException
     */
    public function createRematch(int $oldGameId): RematchResponseDto;
}
