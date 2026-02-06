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
 * Payload DTO for creating a room via API.
 */
final class RoomCreateRequest
{
    /**
     * @param int|null       $previousGameId
     * @param list<int>|null $playerIds
     * @param list<int>|null $excludePlayerIds
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        #[Assert\Positive]
        public ?int $previousGameId = null,
        /**
         * @var list<int>|null
         */
        #[Assert\Type('array')]
        #[Assert\All([
            new Assert\Type('integer'),
            new Assert\Positive(),
        ])]
        #[Assert\Unique]
        public ?array $playerIds = null,
        /**
         * @var list<int>|null
         */
        #[Assert\Type('array')]
        #[Assert\All([
            new Assert\Type('integer'),
            new Assert\Positive(),
        ])]
        #[Assert\Unique]
        public ?array $excludePlayerIds = null,
    ) {
    }
}
