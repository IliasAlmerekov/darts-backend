<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\ThrowAckDto;
use App\Dto\UndoAckDto;
use App\Dto\ThrowRequest;
use App\Entity\Game;

/**
 * Interface for game throw service operations.
 */
interface GameThrowServiceInterface
{
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
     * Undo a throw and build its acknowledgement while the game is locked.
     *
     * @param Game                     $game
     * @param GameStateReaderInterface $reader
     *
     * @return UndoAckDto
     */
    public function undoThrowAck(Game $game, GameStateReaderInterface $reader): UndoAckDto;
}
