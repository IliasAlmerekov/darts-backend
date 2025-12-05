<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GamePlayers;

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
     * @psalm-suppress PossiblyUnusedMethod
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
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param array<string, mixed> $criteria
     *
     * @return int
     */
    public function count(array $criteria): int;

    /**
     * @param int $gameId
     *
     * @return int
     */
    public function countFinishedPlayers(int $gameId): int;

    /**
     * @param int $gameId
     *
     * @return array<int, array{id:int|null,name:string|null}>
     */
    public function findPlayersWithUserInfo(int $gameId): array;

    /**
     * @param int $gameId
     *
     * @return GamePlayers[]
     */
    public function findByGameId(int $gameId): array;

    /**
     * @param int $gameId
     * @param int $playerId
     *
     * @return bool
     */
    public function isPlayerInGame(int $gameId, int $playerId): bool;
}
