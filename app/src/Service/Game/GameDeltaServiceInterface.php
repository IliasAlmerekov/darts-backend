<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\UndoAckDto;
use App\Dto\ThrowAckDto;
use App\Dto\ThrowDeltaDto;
use App\Entity\Game;

/**
 * Creates lightweight throw delta payloads.
 */
interface GameDeltaServiceInterface
{
    /**
     * @param Game                      $game
     * @param array<string, mixed>|null $latestThrow
     *
     * @return ThrowAckDto
     */
    public function buildThrowAck(Game $game, ?array $latestThrow = null): ThrowAckDto;

    /**
     * @param Game               $game
     * @param ThrowDeltaDto|null $undoneThrow
     *
     * @return UndoAckDto
     */
    public function buildUndoAck(Game $game, ?ThrowDeltaDto $undoneThrow = null): UndoAckDto;
}
