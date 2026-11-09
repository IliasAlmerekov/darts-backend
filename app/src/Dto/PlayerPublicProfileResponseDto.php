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
 * The Public profile of a player: what every other player may see of their Profile.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class PlayerPublicProfileResponseDto
{
    /**
     * @param int                   $playerId
     * @param string                $displayName the display name, else the username
     * @param PlayerProfileStatsDto $stats
     */
    public function __construct(
        public int $playerId,
        #[OA\Property(description: 'Anzeigename des Spielers, sonst sein Benutzername.')]
        public string $displayName,
        public PlayerProfileStatsDto $stats,
    ) {
    }
}
