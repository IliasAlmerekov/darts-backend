<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\RoomCreateRequest;
use App\Entity\Game;
use App\Exception\Game\ConflictingOutRulesException;
use App\Exception\Game\GameRoomFullException;
use App\Exception\Game\PlayerNotFoundException;
use Doctrine\ORM\Exception\ORMException;

/**
 * Interface for game room service operations.
 */
interface GameRoomServiceInterface
{
    /**
     * Return the game in the lobby (ADR 0003). Only when this call creates it, place the
     * players from `playerIds` without `excludePlayerIds` into it. Then apply `startScore`,
     * `doubleOut` and `tripleOut` when the request sends any of them. Opens its own
     * transaction through RetryingTransactionInterface::run(), so a rejected request leaves
     * no new lobby behind.
     *
     * @param RoomCreateRequest $request
     *
     * @return Game The lobby game
     *
     * @throws ORMException
     * @throws PlayerNotFoundException      When an included player id belongs to no user
     * @throws GameRoomFullException        When a new lobby would seat more than Game::MAX_PARTICIPANTS players
     * @throws ConflictingOutRulesException When the lobby would end up with double out and triple out
     */
    public function openRoom(RoomCreateRequest $request): Game;

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
