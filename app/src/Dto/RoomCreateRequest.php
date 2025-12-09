<?php

declare(strict_types=1);

namespace App\Dto;

final class RoomCreateRequest
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        public ?int $previousGameId = null,
        /** @var int[]|null */
        public ?array $playerIds = null,
        /** @var int[]|null */
        public ?array $excludePlayerIds = null,
    ) {
    }
}
