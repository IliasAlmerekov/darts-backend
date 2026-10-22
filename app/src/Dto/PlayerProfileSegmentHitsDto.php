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
 * How often a player's darts hit one board segment, any ring.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class PlayerProfileSegmentHitsDto
{
    /** The board segments in board order, as hitDistribution lists them. */
    public const array SEGMENTS = [
        '1', '2', '3', '4', '5', '6', '7', '8', '9', '10',
        '11', '12', '13', '14', '15', '16', '17', '18', '19', '20',
        'BULL', 'BULLSEYE',
    ];

    /**
     * @param string $segment 1 to 20, BULL or BULLSEYE
     * @param int    $hits
     */
    public function __construct(
        #[OA\Property(enum: self::SEGMENTS)]
        public string $segment,
        public int $hits,
    ) {
    }
}
