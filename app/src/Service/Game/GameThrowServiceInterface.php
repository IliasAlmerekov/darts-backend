<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\ThrowRecordingResultDto;
use App\Dto\ThrowDeltaDto;
use App\Dto\ThrowRequest;
use App\Entity\Game;
use App\Exception\ApiExceptionInterface;

/**
 * Interface for game throw service operations.
 */
interface GameThrowServiceInterface
{
    /**
     * Record a throw in the game.
     * Updates the game state, calculates scores, checks for bust conditions,
     * and determines if a player has won.
     *
     * @param Game         $game The game to record the throw in
     * @param ThrowRequest $dto  The throw data (player, value, double/triple flags)
     *
    * @throws ApiExceptionInterface If the throw is invalid for current turn/state
     *
    * @return ThrowRecordingResultDto Post-throw context for compact delta acknowledgements
     */
    public function recordThrow(Game $game, ThrowRequest $dto): ThrowRecordingResultDto;

    /**
     * Record a throw by game id.
     * Resolves and locks the game inside a transaction before applying throw rules.
     *
     * @param int          $gameId The game id to record the throw in
     * @param ThrowRequest $dto    The throw data (player, value, double/triple flags)
     *
     * @throws ApiExceptionInterface If the game is missing or the throw is invalid
     *
     * @return ThrowRecordingResultDto Post-throw context for compact delta acknowledgements
     */
    public function recordThrowByGameId(int $gameId, ThrowRequest $dto): ThrowRecordingResultDto;

    /**
     * Undo the last throw in the game.
     * Removes the last throw, restores player scores, resets positions,
     * and potentially changes the game status back to Started.
     *
     * @param Game $game The game to undo the last throw in
     *
     * @return ThrowDeltaDto|null Snapshot of the removed throw for compact acknowledgements
     */
    public function undoLastThrow(Game $game): ?ThrowDeltaDto;
}
