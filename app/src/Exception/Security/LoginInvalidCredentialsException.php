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
 * Thrown for every failed login, so the answer never tells whether the account exists.
 */
final class LoginInvalidCredentialsException extends ApiHttpException
{
    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            ErrorCode::LoginInvalidCredentials,
            Response::HTTP_UNAUTHORIZED,
            'Invalid email or password'
        );
    }
}
