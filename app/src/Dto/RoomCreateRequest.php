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
     * @param int|null       $startScore
     * @param bool|null      $doubleOut
     * @param bool|null      $tripleOut
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
        #[Assert\Choice(choices: [101, 201, 301, 401, 501])]
        public ?int $startScore = null,
        #[Assert\Type('bool')]
        public ?bool $doubleOut = null,
        #[Assert\Type('bool')]
        public ?bool $tripleOut = null,
    ) {
    }
}
