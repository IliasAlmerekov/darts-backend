<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

/**
 * Standard success response with a human-readable message.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class SuccessMessageDto
{
    /**
     * @param string $message
     * @param bool   $success
     */
    public function __construct(
        public string $message,
        public bool $success = true,
    ) {
    }
}
