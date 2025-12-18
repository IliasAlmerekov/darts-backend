<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload for updating player positions in a room.
 */
final class UpdatePlayerOrderRequest
{
    /**
     * @param list<array{playerId:int, position:int}> $positions
     *
     * @psalm-suppress PossiblyUnusedMethod Constructor is used via serializer payload mapping
     */
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Count(min: 1)]
        #[Assert\All([
            new Assert\Collection([
                'playerId' => [new Assert\NotNull(), new Assert\Type('integer'), new Assert\Positive()],
                'position' => [new Assert\NotNull(), new Assert\Type('integer'), new Assert\PositiveOrZero()],
            ]),
        ])]
        public array $positions = [],
    ) {
    }
}
