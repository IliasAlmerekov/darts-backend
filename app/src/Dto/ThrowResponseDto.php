<?php

declare(strict_types=1);

namespace App\Dto;

class ThrowResponseDto
{
    public function __construct(
        public int $value,
        public bool $isDouble,
        public bool $isTriple,
        public bool $isBust,
    ) {}
}
