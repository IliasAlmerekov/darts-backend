<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

/**
 * Compact scoreboard row used in delta updates.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class ScoreboardPlayerDeltaDto
{
    /**
     * @param int       $playerId
     * @param string    $name
     * @param int       $score
     * @param int|null  $position
     * @param bool      $isActive
     * @param bool      $isGuest
     * @param bool|null $isBust
     */
    public function __construct(
        public int $playerId,
        public string $name,
        public int $score,
        public ?int $position,
        public bool $isActive,
        public bool $isGuest,
        public ?bool $isBust = null,
    ) {
    }
}
