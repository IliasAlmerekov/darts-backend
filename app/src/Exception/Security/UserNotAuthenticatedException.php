<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Exception\Security;

use App\Exception\ApiHttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when an authenticated user is required but none is present.
 */
final class UserNotAuthenticatedException extends ApiHttpException
{
    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            'USER_NOT_AUTHENTICATED',
            Response::HTTP_UNAUTHORIZED,
            'User not authenticated'
        );
    }
}
