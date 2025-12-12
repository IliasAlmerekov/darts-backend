<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

/**
 * Payload DTO for creating a room via API.
 */
final class RoomCreateRequest
{
    /**
     * @param int|null   $previousGameId
     * @param int[]|null $playerIds
     * @param int[]|null $excludePlayerIds
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(public ?int $previousGameId = null, /** @var int[]|null */ public ?array $playerIds = null, /** @var int[]|null */ public ?array $excludePlayerIds = null)
    {
    }
}
