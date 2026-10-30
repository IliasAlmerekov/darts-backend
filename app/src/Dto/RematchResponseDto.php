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
 * Rematch response: the game in the lobby and the link that invites players to it.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
#[OA\Schema(required: ['success', 'gameId', 'invitationLink'])]
final class RematchResponseDto
{
    // Declared before the constructor, so the JSON body starts with `success`.
    #[OA\Property(example: true)]
    public bool $success = true;

    /**
     * @param int    $gameId
     * @param string $invitationLink
     */
    public function __construct(
        #[OA\Property(example: 456)]
        public int $gameId,
        #[OA\Property(example: '/api/invite/join/xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')]
        public string $invitationLink,
    ) {
    }
}
