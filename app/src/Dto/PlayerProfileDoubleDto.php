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
 * A double a player checked out with in double-out games, and how often they tried it.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class PlayerProfileDoubleDto
{
    /** The doubles a favorite double can name. */
    public const array DOUBLES = [
        'D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7', 'D8', 'D9', 'D10',
        'D11', 'D12', 'D13', 'D14', 'D15', 'D16', 'D17', 'D18', 'D19', 'D20',
        'BULLSEYE',
    ];

    /**
     * @param string $double   D1 to D20 or BULLSEYE
     * @param int    $hits     checkouts with this double
     * @param int    $attempts darts thrown while the remaining score equalled this double
     * @param float  $hitRate  hits per 100 attempts, rounded to one decimal
     */
    public function __construct(
        #[OA\Property(enum: self::DOUBLES)]
        public string $double,
        public int $hits,
        #[OA\Property(description: 'Darts, geworfen bei einem Rest von genau diesem Double.')]
        public int $attempts,
        #[OA\Property(minimum: 0, maximum: 100)]
        public float $hitRate,
    ) {
    }
}
