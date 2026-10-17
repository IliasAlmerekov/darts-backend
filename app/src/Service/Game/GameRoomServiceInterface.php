<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use Doctrine\ORM\Exception\ORMException;

/**
 * Interface for game room service operations.
 */
interface GameRoomServiceInterface
{
    /**
     * Return the game in the lobby, creating it when no game is in the lobby.
     *
     * @return OpenLobby The lobby game, and whether this call created it
     */
    public function openLobby(): OpenLobby;

    /**
     * Return the game in the lobby. Only when this call creates it, place players from a
     * previous game or from the include list into it.
     *
     * @param int|null       $previousGameId   ID of the previous game to copy players from
     * @param list<int>|null $includePlayerIds Explicit list of players to place into the new game
     * @param list<int>|null $excludePlayerIds Players to omit from the include list
     *
     * @return Game The lobby game
     *
     * @throws ORMException
     */
    public function openRoom(?int $previousGameId = null, ?array $includePlayerIds = null, ?array $excludePlayerIds = null): Game;

    /**
     * Find a game by its ID.
     *
     * @param int $id Game ID
     *
     * @return Game|null Game entity or null if not found
     */
    public function findGameById(int $id): ?Game;

    /**
     * Get players with user information for a specific game.
     *
     * @param int $gameId Game ID
     *
     * @return array<int, array<string, mixed>> Array of player data with user information
     */
    public function getPlayersWithUserInfo(int $gameId): array;
}
