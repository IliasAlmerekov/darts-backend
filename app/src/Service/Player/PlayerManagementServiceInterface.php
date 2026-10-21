<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Player;

use App\Entity\GamePlayers;
use App\Entity\User;
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
     * Add a user entity to a game.
     *
     * @param int      $gameId   The game ID
     * @param User     $player   The player entity to add
     * @param int|null $position Optional explicit position
     * @param bool     $flush    Whether to flush immediately
     *
     * @return GamePlayers The created GamePlayers entity
     *
     * @throws ORMException
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function addPlayerEntity(int $gameId, User $player, ?int $position = null, bool $flush = true): GamePlayers;

    /**
     * Copy every player from one game to another.
     *
     * @param int $fromGameId Source game ID
     * @param int $toGameId   Target game ID
     *
     * @throws ORMException
     *
     * @return void
     */
    public function copyPlayers(int $fromGameId, int $toGameId): void;

    /**
     * Seat every player of the source game who is missing from the target game. They take
     * the positions after the players already in the target game, in their source order.
     * Once the target game has Game::MAX_PARTICIPANTS seats, the rest are skipped.
     * Must be called inside a transaction, because it reads the target seats with a lock.
     *
     * @param int $fromGameId Source game ID
     * @param int $toGameId   Target game ID
     *
     * @throws ORMException
     *
     * @return void
     */
    public function appendMissingPlayers(int $fromGameId, int $toGameId): void;

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
