<?php

declare(strict_types=1);

namespace App\Dto;

class GameResponseDto
{
    public function __construct(
        public int $id,
        public string $status,
        public int $currentRound,
        public ?int $activePlayerId,
        public int $currentThrowCount,
        /** @var PlayerResponseDto[] */
        public array $players,
        public ?int $winnerId,
        public array $settings,
    ) {}
}
