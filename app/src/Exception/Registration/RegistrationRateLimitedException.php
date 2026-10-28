<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Exception\Registration;

use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when one client IP exceeds the registration limit.
 */
final class RegistrationRateLimitedException extends ApiHttpException
{
    /**
     * @param DateTimeImmutable $retryAfter
     */
    public function __construct(DateTimeImmutable $retryAfter)
    {
        $seconds = max(1, $retryAfter->getTimestamp() - time());

        parent::__construct(
            ErrorCode::RegistrationRateLimited,
            Response::HTTP_TOO_MANY_REQUESTS,
            'Too many registration attempts. Try again later.',
            null,
            ['Retry-After' => (string) $seconds],
        );
    }
}
