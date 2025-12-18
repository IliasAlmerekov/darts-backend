<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Marker interface for exceptions that should be returned as JSON API errors.
 */
interface ApiExceptionInterface extends \Throwable
{
    /**
     * Stable machine-readable error code (e.g. "PLAYER_NOT_FOUND").
     */
    public function getErrorCode(): string;
}
