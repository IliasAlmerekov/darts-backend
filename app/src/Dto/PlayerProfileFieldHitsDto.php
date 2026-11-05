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
 * How often a player's darts hit one board field.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class PlayerProfileFieldHitsDto
{
    /** The board fields in the order hitDistribution lists them. */
    public const array FIELDS = [
        'S1', 'D1', 'T1', 'S2', 'D2', 'T2', 'S3', 'D3', 'T3', 'S4', 'D4', 'T4',
        'S5', 'D5', 'T5', 'S6', 'D6', 'T6', 'S7', 'D7', 'T7', 'S8', 'D8', 'T8',
        'S9', 'D9', 'T9', 'S10', 'D10', 'T10', 'S11', 'D11', 'T11', 'S12', 'D12', 'T12',
        'S13', 'D13', 'T13', 'S14', 'D14', 'T14', 'S15', 'D15', 'T15', 'S16', 'D16', 'T16',
        'S17', 'D17', 'T17', 'S18', 'D18', 'T18', 'S19', 'D19', 'T19', 'S20', 'D20', 'T20',
        'BULL', 'BULLSEYE',
    ];

    /**
     * @param string $field S1 to T20, BULL or BULLSEYE
     * @param int    $hits
     */
    public function __construct(
        #[OA\Property(enum: self::FIELDS)]
        public string $field,
        public int $hits,
    ) {
    }
}
