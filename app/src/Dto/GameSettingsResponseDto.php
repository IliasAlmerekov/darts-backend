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
 * Lightweight DTO for game settings reads.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class GameSettingsResponseDto
{
    /**
     * @param int    $gameId
     * @param int    $startScore
     * @param bool   $doubleOut
     * @param bool   $tripleOut
     * @param string $status
     */
    public function __construct(
        public int $gameId,
        public int $startScore,
        public bool $doubleOut,
        public bool $tripleOut,
        public string $status,
    ) {
    }
}
