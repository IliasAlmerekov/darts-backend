<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

use App\Enum\GameStatus;
use OpenApi\Attributes as OA;

/**
 * @psalm-immutable
 * The game a player currently belongs to; both fields are null when there is none.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
#[OA\Schema(required: ['gameId', 'status'])]
final class CurrentGameResponseDto
{
    /**
     * @param int|null    $gameId
     * @param string|null $status GameStatus value, `lobby` or `started`
     */
    public function __construct(
        public ?int $gameId,
        #[OA\Property(type: 'string', nullable: true, enum: [GameStatus::Lobby->value, GameStatus::Started->value, null])]
        public ?string $status,
    ) {
    }
}
