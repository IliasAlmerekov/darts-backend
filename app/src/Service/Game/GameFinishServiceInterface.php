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
use App\Entity\GamePlayers;
use DateTimeInterface;

/**
 * Interface for game finishing service.
 */
interface GameFinishServiceInterface
{
    /**
     * @param Game        $game
     * @param GamePlayers $seat
     *
     * @return void
     */
    public function recordCheckout(Game $game, GamePlayers $seat): void;

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
     * @param int $gameId
     *
     * @return list<array{
     *     playerId:int|null,
     *     username:string|null,
     *     position:int|null,
     *     placement:int|null,
     *     roundsPlayed:int,
     *     roundAverage:float
     * }>
     */
    public function buildFinishedPlayersList(int $gameId): array;
}
