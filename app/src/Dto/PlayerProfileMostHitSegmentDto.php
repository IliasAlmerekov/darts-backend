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
    /** The board segments in order; a later one wins a tie. */
    public const array SEGMENTS = [
        '1', '2', '3', '4', '5', '6', '7', '8', '9', '10',
        '11', '12', '13', '14', '15', '16', '17', '18', '19', '20',
        'BULL', 'BULLSEYE',
    ];

    /**
     * @param string $segment 1 to 20, BULL or BULLSEYE
     * @param int    $hits
     * @param float  $share   hits per 100 darts thrown, misses included, rounded to one decimal
     */
    public function __construct(
        #[OA\Property(enum: self::SEGMENTS)]
        public string $segment,
        public int $hits,
        #[OA\Property(description: 'Treffer pro 100 geworfene Darts, Fehlwürfe eingeschlossen.', minimum: 0, maximum: 100)]
        public float $share,
    ) {
    }
}
