<?php

declare(strict_types=1);

namespace App\Service\Game;

use Doctrine\ORM\Exception\ORMException;

/**
 * Interface for rematch service operations.
 */
interface RematchServiceInterface
{
    /**
     * Create a rematch from an existing game.
    * Creates a new game, copies players from the old game, and returns only rematch-related data.
     * Must be called inside a transaction: the lobby row stays locked until it ends, so
     * concurrent rematches into the same lobby cannot add a participant twice.
     *
     * @param int $oldGameId The ID of the game to rematch
     *
    * @return array{success: bool, gameId?: int, invitationLink?: string, message?: string} Array containing:
     *                               - 'success' (bool): Whether the rematch was created successfully
     *                               - 'gameId' (int): The new game ID (on success)
     *                               - 'invitationLink' (string): URL to join the new game (on success)
     *                               - 'message' (string): Error message (on failure)
     *
     * @throws ORMException
     */
    public function createRematch(int $oldGameId): array;
}
