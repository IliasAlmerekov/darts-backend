<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

/**
 * Payload DTO carrying a player identifier.
 */
final class PlayerIdPayload
{
    /**
     * @param int|null $playerId
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(public ?int $playerId = null)
    {
    }
}
