<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Player;

use App\Entity\GamePlayers;
use Doctrine\ORM\Exception\ORMException;

/**
 * Interface for player management service operations.
 */
interface PlayerManagementServiceInterface
{
    /**
     * Remove a player from a game.
     *
     * @param int $gameId   The game ID
     * @param int $playerId The player ID to remove
     *
     * @return bool True if the player was removed, false if not found
     */
    public function removePlayer(int $gameId, int $playerId): bool;

    /**
     * Add a player to a game.
     *
     * @param int      $gameId   The game ID
     * @param int      $playerId The player ID to add
     * @param int|null $position Optional explicit position
     *
     * @return GamePlayers The created GamePlayers entity
     *
     * @throws ORMException
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function addPlayer(int $gameId, int $playerId, ?int $position = null): GamePlayers;

    /**
     * Copy players from one game to another.
     * If a filter list is provided, only those players are copied.
     *
     * @param int            $fromGameId Source game ID
     * @param int            $toGameId   Target game ID
     * @param list<int>|null $playerIds  Optional list of player IDs to copy (null = copy all)
     *
     * @throws ORMException
     *
     * @return void
     */
    public function copyPlayers(int $fromGameId, int $toGameId, ?array $playerIds = null): void;

    /**
     * Update player positions for a game.
     *
     * @param int                                           $gameId
     * @param list<array{playerId:int, position:int}>|array $positions
     *
     * @return void
     */
    public function updatePlayerPositions(int $gameId, array $positions): void;
}
