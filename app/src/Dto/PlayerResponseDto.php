<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * This class is used to serialize player stats
 */
final class PlayerResponseDto
{
    /**
     * @param int                                                   $id
     * @param string                                                $name
     * @param int                                                   $score
     * @param bool                                                  $isActive
     * @param bool                                                  $isBust
     * @param int|null                                              $position
     * @param int                                                   $throwsInCurrentRound
     * @param list<ThrowResponseDto>                                $currentRoundThrows
     * @param list<array{round:int, throws:list<ThrowResponseDto>}> $roundHistory
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $score,
        public bool $isActive,
        public bool $isBust,
        public ?int $position = null,
        public int $throwsInCurrentRound = 0,
        public array $currentRoundThrows = [],
        public array $roundHistory = [],
    ) {
    }
}
