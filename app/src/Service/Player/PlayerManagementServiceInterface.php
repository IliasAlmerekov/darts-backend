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
use App\Exception\Game\GameNotFoundException;
use App\Exception\Game\GameRoomFullException;
use App\Exception\Game\InvalidPlayerOrderException;
use App\Exception\Game\PlayerNotFoundException;
use App\Exception\Game\SeatsNotEditableException;
use Closure;
use Doctrine\ORM\Exception\ORMException;

/**
 * The seat module: the only way to add, remove or reorder the seats of a game. Every method
 * locks the game with its seats and rejects a change outside the lobby; a new seat also
 * respects Game::MAX_PARTICIPANTS and gets the next position and the display name snapshot.
 */
interface PlayerManagementServiceInterface
{
    /**
     * Remove a player from a game.
     *
     * @param int $gameId   The game ID
     * @param int $playerId The player ID to remove
     *
     * @return bool True if the player was removed, false if the player has no seat in the game
     *
     * @throws GameNotFoundException     When the game does not exist
     * @throws SeatsNotEditableException When the game is no longer in the lobby
     */
    public function removePlayer(int $gameId, int $playerId): bool;

    /**
     * Seat a player in a game. A player who already has a seat keeps it, whatever the game's status.
     *
     * @param int $gameId   The game ID
     * @param int $playerId The player ID to add
     *
     * @return void
     *
     * @throws GameNotFoundException     When the game does not exist
     * @throws SeatsNotEditableException When the player has no seat and the game is no longer in the lobby
     * @throws GameRoomFullException     When the player has no seat and the game has Game::MAX_PARTICIPANTS seats
     * @throws ORMException
     */
    public function addPlayer(int $gameId, int $playerId): void;

    /**
     * Seat a player who does not exist yet, such as a guest. The closure runs under the game
     * lock once the seat rules pass, and may throw to reject the player; the player is then
     * persisted and flushed together with the seat.
     *
     * @param int             $gameId       The game ID
     * @param Closure(): User $createPlayer Builds the new player
     *
     * @return GamePlayers The new seat
     *
     * @throws GameNotFoundException     When the game does not exist
     * @throws SeatsNotEditableException When the game is no longer in the lobby
     * @throws GameRoomFullException     When the game has Game::MAX_PARTICIPANTS seats
     */
    public function addNewPlayer(int $gameId, Closure $createPlayer): GamePlayers;

    /**
     * Seat several players in the given order, or none of them.
     *
     * @param int       $gameId    The game ID
     * @param list<int> $playerIds Distinct player IDs
     *
     * @return void
     *
     * @throws GameNotFoundException     When the game does not exist
     * @throws SeatsNotEditableException When the game is no longer in the lobby
     * @throws GameRoomFullException     When the game would have more than Game::MAX_PARTICIPANTS seats
     * @throws PlayerNotFoundException   When an ID belongs to no user
     */
    public function addPlayers(int $gameId, array $playerIds): void;

    /**
     * Copy every player from one game to another. Once the target game has
     * Game::MAX_PARTICIPANTS seats, the rest are skipped.
     *
     * @param int  $fromGameId         Source game ID
     * @param int  $toGameId           Target game ID
     * @param bool $reverseByPlacement Take the seats in reverse placement order (last placed first) and number them 1, 2, 3
     *
     * @throws GameNotFoundException     When the target game does not exist
     * @throws SeatsNotEditableException When the target game is no longer in the lobby
     * @throws ORMException
     *
     * @return void
     */
    public function copyPlayers(int $fromGameId, int $toGameId, bool $reverseByPlacement = false): void;

    /**
     * Seat every player of the source game who is missing from the target game. They take
     * the positions after the players already in the target game, in their source order, or reverse placement order.
     * Once the target game has Game::MAX_PARTICIPANTS seats, the rest are skipped.
     *
     * @param int  $fromGameId         Source game ID
     * @param int  $toGameId           Target game ID
     * @param bool $reverseByPlacement Take the seats in reverse placement order (last placed first)
     *
     * @throws GameNotFoundException     When the target game does not exist
     * @throws SeatsNotEditableException When the target game is no longer in the lobby
     * @throws ORMException
     *
     * @return void
     */
    public function appendMissingPlayers(int $fromGameId, int $toGameId, bool $reverseByPlacement = false): void;

    /**
     * Update player positions for a game.
     *
     * @param int                                           $gameId
     * @param list<array{playerId:int, position:int}>|array $positions
     *
     * @return void
     *
     * @throws GameNotFoundException       When no game has this ID
     * @throws SeatsNotEditableException   When the game is no longer in the lobby
     * @throws InvalidPlayerOrderException When the positions do not cover every seat exactly once
     */
    public function updatePlayerPositions(int $gameId, array $positions): void;
}
