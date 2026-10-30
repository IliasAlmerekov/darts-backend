<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\ThrowRecordingResultDto;
use App\Dto\ThrowAckDto;
use App\Dto\UndoAckDto;
use App\Entity\RoundThrows;
use App\Dto\ThrowRequest;
use App\Entity\Game;
use App\Exception\ApiExceptionInterface;

/**
 * Interface for game throw service operations.
 */
interface GameThrowServiceInterface
{
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
     *
     * @psalm-suppress PossiblyUnusedMethod The existing service API remains available to other callers.
     */
    public function recordThrowByGameId(int $gameId, ThrowRequest $dto): ThrowRecordingResultDto;

    /**
     * Record a throw and build its acknowledgement while the game is locked.
     *
     * @param int                      $gameId
     * @param ThrowRequest             $dto
     * @param GameStateReaderInterface $reader
     *
     * @return ThrowAckDto
     */
    public function recordThrowAck(int $gameId, ThrowRequest $dto, GameStateReaderInterface $reader): ThrowAckDto;

    /**
     * Undo the last throw in the game.
     * Removes the last throw, restores player scores, resets positions,
     * and potentially changes the game status back to Started.
     *
     * @param Game $game The game to undo the last throw in
     *
     * @return RoundThrows|null Snapshot of the removed throw for compact acknowledgements
     *
     * @psalm-suppress PossiblyUnusedMethod The existing service API remains available to other callers.
     */
    public function undoLastThrow(Game $game): ?RoundThrows;

    /**
     * Undo a throw and build its acknowledgement while the game is locked.
     *
     * @param Game                     $game
     * @param GameStateReaderInterface $reader
     *
     * @return UndoAckDto
     */
    public function undoThrowAck(Game $game, GameStateReaderInterface $reader): UndoAckDto;
}
