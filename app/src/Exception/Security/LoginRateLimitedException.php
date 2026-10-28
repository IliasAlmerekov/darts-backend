<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Exception\Security;

use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when login throttling blocks an attempt.
 */
final class LoginRateLimitedException extends ApiHttpException
{
    /**
     * @param int|null $retryAfterSeconds
     */
    public function __construct(?int $retryAfterSeconds)
    {
        parent::__construct(
            ErrorCode::LoginRateLimited,
            Response::HTTP_TOO_MANY_REQUESTS,
            'Too many failed login attempts. Try again later.',
            null,
            null !== $retryAfterSeconds && $retryAfterSeconds > 0 ? ['Retry-After' => (string) $retryAfterSeconds] : [],
        );
    }
}
