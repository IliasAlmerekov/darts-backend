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
 * The Profile of the logged-in player.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class PlayerProfileResponseDto
{
    /**
     * @param PlayerProfileStatsDto      $stats
     * @param list<PlayerProfileGameDto> $recentGames  at most 10 finished games, newest first
     * @param string|null                $registeredAt the Registration date as an ATOM timestamp
     */
    public function __construct(
        public PlayerProfileStatsDto $stats,
        public array $recentGames,
        #[OA\Property(type: 'string', format: 'date-time', nullable: true)]
        public ?string $registeredAt,
    ) {
    }
}
