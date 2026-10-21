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
 * @psalm-immutable
 * The tablet game and the last finished game; each is null when there is none.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
#[OA\Schema(required: ['game', 'lastFinishedGameId'])]
final class TabletGameResponseDto
{
    /**
     * @param TabletGameDto|null $game
     * @param int|null           $lastFinishedGameId
     */
    public function __construct(
        public ?TabletGameDto $game,
        public ?int $lastFinishedGameId,
    ) {
    }
}
