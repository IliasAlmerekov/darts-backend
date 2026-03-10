<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\UndoAckDto;
use App\Dto\ThrowAckDto;
use App\Dto\ThrowDeltaDto;
use App\Entity\Game;

/**
 * Creates lightweight throw delta payloads.
 *
 * @phpstan-type RoundStateSnapshot array<int, array{throwsCount:int,lastThrowNumber:int|null,lastThrowValue:int|null,lastThrowBust:bool}>
 */
interface GameDeltaServiceInterface
{
    /**
     * @param Game                      $game
     * @param array<string, mixed>|null $latestThrow
     * @param RoundStateSnapshot|null   $currentRoundStateSnapshot
     *
     * @return ThrowAckDto
     */
    public function buildThrowAck(Game $game, ?array $latestThrow = null, ?array $currentRoundStateSnapshot = null): ThrowAckDto;

    /**
     * @param Game               $game
     * @param ThrowDeltaDto|null $undoneThrow
     *
     * @return UndoAckDto
     */
    public function buildUndoAck(Game $game, ?ThrowDeltaDto $undoneThrow = null): UndoAckDto;
}
