<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

use OpenApi\Attributes as OA;

/**
 * Room create response: the game in the lobby (ADR 0003).
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
#[OA\Schema(required: ['success', 'gameId'])]
final class RoomCreateResponseDto
{
    // Declared before the constructor, so the JSON body starts with `success`.
    #[OA\Property(example: true)]
    public bool $success = true;

    /**
     * @param int $gameId
     */
    public function __construct(
        #[OA\Property(example: 123)]
        public int $gameId,
    ) {
    }
}
