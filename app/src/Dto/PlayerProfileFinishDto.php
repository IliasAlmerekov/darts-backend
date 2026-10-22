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
 * A player's highest Finish.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class PlayerProfileFinishDto
{
    /**
     * @param int          $score  remaining score when the turn began
     * @param int          $darts  darts in the turn, 1 to 3
     * @param list<string> $throws the darts in order: S<n>, D<n>, T<n>, BULL, BULLSEYE or MISS
     */
    public function __construct(
        #[OA\Property(description: 'Restpunktzahl zu Beginn der Aufnahme.')]
        public int $score,
        #[OA\Property(minimum: 1, maximum: 3)]
        public int $darts,
        #[OA\Property(
            description: 'Die Darts der Aufnahme in Reihenfolge: `S<n>`, `D<n>`, `T<n>` mit n von 1 bis 20, `BULL`, `BULLSEYE` oder `MISS`.',
            maxItems: 3,
            minItems: 1
        )]
        public array $throws,
    ) {
    }
}
