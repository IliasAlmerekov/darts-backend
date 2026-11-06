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
 * A player's hits per board field, split by the out rule each game finished with. Every list
 * has the shape and order of PlayerProfileStatsDto::$hitDistribution.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
#[OA\Schema(description: '`hitDistribution` getrennt nach der Out-Regel, mit der das Spiel endete. Je Out-Regel 62 Einträge in derselben Reihenfolge; eine Out-Regel ohne Spiele hat überall 0. Feldweise summiert ergeben die drei Listen `hitDistribution`.')]
final class PlayerProfileHitDistributionByOutRuleDto
{
    /**
     * @param list<PlayerProfileFieldHitsDto> $singleout 62 entries from single-out games
     * @param list<PlayerProfileFieldHitsDto> $doubleout 62 entries from double-out games
     * @param list<PlayerProfileFieldHitsDto> $tripleout 62 entries from triple-out games
     */
    public function __construct(
        #[OA\Property(description: 'Trefferverteilung der Single-Out-Spiele, aufgebaut wie `hitDistribution`.', maxItems: 62, minItems: 62)]
        public array $singleout,
        #[OA\Property(description: 'Trefferverteilung der Double-Out-Spiele, aufgebaut wie `hitDistribution`.', maxItems: 62, minItems: 62)]
        public array $doubleout,
        #[OA\Property(description: 'Trefferverteilung der Triple-Out-Spiele, aufgebaut wie `hitDistribution`.', maxItems: 62, minItems: 62)]
        public array $tripleout,
    ) {
    }
}
