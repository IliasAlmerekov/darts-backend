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
 * Guest add response: the guest player and the seat they got.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
#[OA\Schema(required: ['success', 'player'])]
final class GuestPlayerResponseDto
{
    // Declared before the constructor, so the JSON body starts with `success`.
    #[OA\Property(example: true)]
    public bool $success = true;

    /**
     * @param GuestPlayerDto $player
     */
    public function __construct(public GuestPlayerDto $player)
    {
    }
}
