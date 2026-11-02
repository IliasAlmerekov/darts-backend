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
 * A game's status and whether the authenticated player still holds a seat in it.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
#[OA\Schema(required: ['gameId', 'status', 'seated'])]
final class GameSeatResponseDto
{
    /**
     * @param int    $gameId
     * @param string $status GameStatus value
     * @param bool   $seated
     */
    public function __construct(
        public int $gameId,
        #[OA\Property(type: 'string', enum: [GameStatus::Lobby->value, GameStatus::Started->value, GameStatus::Finished->value, GameStatus::Aborted->value])]
        public string $status,
        public bool $seated,
    ) {
    }
}
