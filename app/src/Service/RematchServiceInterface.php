<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\Exception\ORMException;

/**
 * Interface for rematch service operations.
 */
interface RematchServiceInterface
{
    /**
     * Create a rematch from an existing game.
     * Creates a new game, copies players from the old game, and finishes the old game.
     *
     * @param int $oldGameId The ID of the game to rematch
     *
     * @return array<string, mixed> Array containing:
     *                               - 'success' (bool): Whether the rematch was created successfully
     *                               - 'gameId' (int): The new game ID (on success)
     *                               - 'invitationLink' (string): URL to join the new game (on success)
     *                               - 'finishedPlayers' (array): Players from the finished game (on success)
     *                               - 'message' (string): Error message (on failure)
     *
     * @throws ORMException
     */
    public function createRematch(int $oldGameId): array;
}
