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
 * The board segment a player's darts hit most often.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class PlayerProfileMostHitSegmentDto
{
    /**
     * @param string $segment 1 to 20, BULL or BULLSEYE
     * @param int    $hits
     * @param float  $share   hits per 100 darts thrown, misses included, rounded to one decimal
     */
    public function __construct(
        #[OA\Property(enum: PlayerProfileSegmentHitsDto::SEGMENTS)]
        public string $segment,
        public int $hits,
        #[OA\Property(description: 'Treffer pro 100 geworfene Darts, Fehlwürfe eingeschlossen.', minimum: 0, maximum: 100)]
        public float $share,
    ) {
    }
}
