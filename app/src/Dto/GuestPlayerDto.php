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
 * The guest player in the guest add response.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class GuestPlayerDto
{
    /**
     * @param int      $id
     * @param string   $name
     * @param int|null $position
     * @param int|null $placement
     * @param bool     $isGuest
     */
    public function __construct(
        #[OA\Property(example: 123)]
        public int $id,
        #[OA\Property(example: 'Alex')]
        public string $name,
        #[OA\Property(example: 2)]
        public ?int $position,
        #[OA\Property(example: null)]
        public ?int $placement,
        #[OA\Property(example: true)]
        public bool $isGuest,
    ) {
    }
}
