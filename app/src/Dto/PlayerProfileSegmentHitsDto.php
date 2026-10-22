<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

/**
 * @psalm-immutable
 * How often a player's darts hit one board segment, any ring.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class PlayerProfileSegmentHitsDto
{
    /**
     * @param string $segment 1 to 20, BULL or BULLSEYE
     * @param int    $hits
     */
    public function __construct(
        public string $segment,
        public int $hits,
    ) {
    }
}
