<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

/**
 * @psalm-immutable
 * The game a player currently belongs to; both fields are null when there is none.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class CurrentGameResponseDto
{
    /**
     * @param int|null    $gameId
     * @param string|null $status GameStatus value, `lobby` or `started`
     */
    public function __construct(
        public ?int $gameId,
        public ?string $status,
    ) {
    }
}
