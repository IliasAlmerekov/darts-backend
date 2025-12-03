<?php

declare(strict_types=1);

namespace App\Dto;

class PlayerResponseDto
{
    public function __construct(
        public int $id,
        public string $name,
        public int $score,
        public bool $isActive,
        public bool $isBust,
        public ?int $position = null,
        public int $throwsInCurrentRound = 0,
        /** @var list<ThrowResponseDto> */
        public array $currentRoundThrows = [],
        /** @var list<array{round:int, throws:list<ThrowResponseDto>}> */
        public array $roundHistory = [],
    ) {}
}
