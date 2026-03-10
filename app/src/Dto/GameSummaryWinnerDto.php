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
 * Winner DTO for game summary responses.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class GameSummaryWinnerDto
{
    /**
     * @param int         $id
     * @param string|null $username
     */
    public function __construct(
        public int $id,
        public ?string $username,
    ) {
    }
}
