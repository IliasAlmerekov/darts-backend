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
        public array $currentRoundThrows = [], // [{ value: 20, isDouble: true, isBust: false }, ...]
        public array $roundHistory = [],
    ) {}
}
