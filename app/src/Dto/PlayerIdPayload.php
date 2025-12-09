<?php

declare(strict_types=1);

namespace App\Dto;

final class PlayerIdPayload
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        public ?int $playerId = null
    ) {
    }
}
