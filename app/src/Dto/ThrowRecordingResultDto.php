<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Game;

/**
 * Carries post-throw data that can be reused when building low-latency acknowledgements.
 *
 * @phpstan-type LatestThrowSnapshot array{id:int,playerId:int,roundNumber:int,throwNumber:int,value:int,isDouble:bool,isTriple:bool,isBust:bool,score:int,playerName:string,timestamp:string}
 * @phpstan-type RoundStateSnapshot array<int, array{throwsCount:int,lastThrowNumber:int|null,lastThrowValue:int|null,lastThrowBust:bool}>
 */
final readonly class ThrowRecordingResultDto
{
    /**
     * @param LatestThrowSnapshot|null $latestThrow
     * @param RoundStateSnapshot       $currentRoundStateSnapshot
     * @param Game                     $game
     */
    public function __construct(
        public ?array $latestThrow,
        public array $currentRoundStateSnapshot,
        public Game $game,
    ) {
    }
}
