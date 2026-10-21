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
 * The tablet game: the game in the lobby or started.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
#[OA\Schema(required: ['id', 'status'])]
final class TabletGameDto
{
    /**
     * @param int    $id
     * @param string $status GameStatus value, `lobby` or `started`
     */
    public function __construct(
        public int $id,
        #[OA\Property(type: 'string', enum: [GameStatus::Lobby->value, GameStatus::Started->value])]
        public string $status,
    ) {
    }
}
