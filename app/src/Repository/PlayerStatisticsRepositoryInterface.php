<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Repository;

use App\Dto\ParticipantTotalsDto;
use App\Enum\OutRule;

/**
 * Contract for the player statistics reads.
 */
interface PlayerStatisticsRepositoryInterface
{
    /**
     * @param list<int> $gameIds
     *
     * @return array<int, array<int, ParticipantTotalsDto>> per game id, then player id
     */
    public function getParticipantTotalsForGames(array $gameIds): array;

    /**
     * @param int    $limit
     * @param int    $offset
     * @param string $sortField `average`, `gamesPlayed` or `name` (display name); ties follow the player id
     * @param string $direction
     *
     * @return array<int, array{
     *     playerId:int,
     *     username:string,
     *     gamesPlayed:string,
     *     totalValue:string,
     *     dartsThrown:string,
     *     scoreAverage:float|string|null
     * }>
     */
    public function getPlayerStatistics(int $limit, int $offset, string $sortField = 'average', string $direction = 'DESC'): array;

    /**
     * @param int $playerId
     *
     * @return array{
     *     playerId:int,
     *     username:string,
     *     gamesPlayed:string,
     *     totalValue:string,
     *     dartsThrown:string,
     *     scoreAverage:float|string|null
     * }|null
     */
    public function getPlayerStatisticsForPlayer(int $playerId): ?array;

    /**
     * @param int $playerId
     *
     * @return list<array{
     *     gameId:int,
     *     roundId:int,
     *     value:int,
     *     isDouble:bool,
     *     isTriple:bool,
     *     isBust:bool,
     *     score:int,
     *     startScore:int,
     *     outRule:OutRule,
     *     placement:int|null
     * }>
     */
    public function findThrowsInCountedGamesForPlayer(int $playerId): array;

    /**
     * @return int
     */
    public function countPlayersWithStatistics(): int;
}
