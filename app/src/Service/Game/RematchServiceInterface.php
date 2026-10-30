<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Exception\Game\GameNotFoundException;
use Doctrine\ORM\Exception\ORMException;

/**
 * Interface for rematch service operations.
 */
interface RematchServiceInterface
{
    /**
     * Create a rematch from an existing game.
    * Creates a new game, copies players from the old game, and returns only rematch-related data.
     * Opens its own transaction through LockedGameTransactionInterface::runWithRetry(): the
     * lobby row stays locked until it ends, so concurrent rematches into the same lobby cannot
     * add a participant twice.
     *
     * @param int $oldGameId The ID of the game to rematch
     *
     * @return array{success: bool, gameId?: int, invitationLink?: string} Array containing:
     *                               - 'success' (bool): always true
     *                               - 'gameId' (int): The new game ID
     *                               - 'invitationLink' (string): URL to join the new game
     *
     * @throws GameNotFoundException When the old game does not exist
     * @throws ORMException
     */
    public function createRematch(int $oldGameId): array;
}
