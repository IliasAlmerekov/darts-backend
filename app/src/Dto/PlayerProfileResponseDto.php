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
 * The Profile of the logged-in player.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class PlayerProfileResponseDto
{
    /**
     * @param PlayerProfileStatsDto      $stats
     * @param list<PlayerProfileGameDto> $recentGames at most 10 finished games, newest first
     */
    public function __construct(
        public PlayerProfileStatsDto $stats,
        public array $recentGames,
    ) {
    }
}
