<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GamePlayers;
use App\Enum\GameStatus;

/**
 * Contract for game players' repository.
 */
interface GamePlayersRepositoryInterface
{
    /**
     * @param array<string, mixed>       $criteria
     * @param array<string, string>|null $orderBy
     *
     * @return object|null
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object;

    /**
     *
     * @param array<string, mixed>       $criteria
     * @param array<string, string>|null $orderBy
     * @param int|null                   $limit
     * @param int|null                   $offset
     *
     * @return GamePlayers[]|array<object>
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array;

    /**
     *
     * @param array<string, mixed> $criteria
     *
     * @return int
     */
    public function count(array $criteria): int;

    /**
     * @param int    $gameId
     * @param string $name
     *
     * @return bool
     */
    public function existsNameInGame(int $gameId, string $name): bool;

    /**
     * Reads with a shared lock (FOR SHARE), so the next position also counts seats
     * committed after the transaction's snapshot was taken.
     *
     * @param int $gameId
     *
     * @return int
     */
    public function findNextPositionForGame(int $gameId): int;

    /**
     * @param int $gameId
     *
     * @return array<int, array{id:int|null,name:string|null,position:int|null,placement:int|null,isGuest:bool}>
     */
    public function findPlayersWithUserInfo(int $gameId): array;

    /**
     * @param int $gameId
     *
     * @return GamePlayers[]
     */
    public function findByGameId(int $gameId): array;

    /**
     * Returns the game's seats with a shared lock (FOR SHARE), so the read also sees seats
     * committed after the transaction's snapshot was taken. Players stay unloaded proxies.
     * Must be called inside a transaction.
     *
     * @param int $gameId
     *
     * @return GamePlayers[]
     */
    public function findByGameIdForShare(int $gameId): array;

    /**
     * @param int $gameId
     *
     * @return array<int, array{playerId:int,name:string,position:int|null,placement:int|null,score:int|null,isGuest:bool}>
     */
    public function findGameStatePlayersByGameId(int $gameId): array;

    /**
     * @param int $gameId
     * @param int $playerId
     *
     * @psalm-suppress PossiblyUnusedMethod Reason: retained for the separate dead-code cleanup in #71.
     *
     * @return bool
     */
    public function isPlayerInGame(int $gameId, int $playerId): bool;

    /**
     * Like isPlayerInGame, but reads with a shared lock (FOR SHARE), so it also sees a seat
     * committed after the transaction's snapshot was taken. Must be called inside a
     * transaction.
     *
     * @param int $gameId
     * @param int $playerId
     *
     * @return bool
     */
    public function isPlayerInGameForShare(int $gameId, int $playerId): bool;

    /**
     * The game the player joined most recently (highest GamePlayers id) among the games
     * they still sit in whose status is Lobby or Started, or null when there is none.
     *
     * @param int $playerId
     *
     * @return array{gameId:int,status:GameStatus}|null
     */
    public function findCurrentGameForPlayer(int $playerId): ?array;

    /**
     * Loads the seats of several games with their players in one query.
     *
     * @param list<int> $gameIds
     *
     * @return array<int, list<GamePlayers>> seats per game id, in seat order
     */
    public function findByGameIds(array $gameIds): array;
}
