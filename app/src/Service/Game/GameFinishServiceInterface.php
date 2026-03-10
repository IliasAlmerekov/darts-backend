<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\GameSummaryResponseDto;
use App\Entity\Game;
use DateTimeInterface;

/**
 * Interface for game finishing service.
 */
interface GameFinishServiceInterface
{
    /**
    * Finish a game and return the normalized summary.
     *
     * @param Game                   $game
     * @param DateTimeInterface|null $finishedAt
     *
        * @return GameSummaryResponseDto
     */
    public function finishGame(Game $game, ?DateTimeInterface $finishedAt = null): GameSummaryResponseDto;

    /**
     * Get statistics for a finished game.
     *
     * @param Game $game
     *
        * @return GameSummaryResponseDto
     */
    public function getGameStats(Game $game): GameSummaryResponseDto;

    /**
     * Get the finished game summary without mutating state.
     *
     * @param Game $game
     *
        * @return GameSummaryResponseDto
     */
    public function getGameSummary(Game $game): GameSummaryResponseDto;

    /**
     * Build the list of finished players for a game.
     *
     * @param int                    $gameId
     * @param int|null               $finishedRounds
     * @param array<int, int>|null   $roundsPlayedMap
     * @param array<int, float>|null $totalScoresMap
     *
     * @return list<array{
     *     playerId:int|null,
     *     username:string|null,
     *     position:int|null,
     *     roundsPlayed:int|null,
     *     roundAverage:float
     * }>
     */
    public function buildFinishedPlayersList(int $gameId, ?int $finishedRounds = null, ?array $roundsPlayedMap = null, ?array $totalScoresMap = null): array;
}
