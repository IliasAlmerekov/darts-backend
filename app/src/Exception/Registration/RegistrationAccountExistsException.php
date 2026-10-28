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
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Thrown when the email or the username of a new player already belongs to an account.
 *
 * The message names no field, so the response does not tell which of the two is taken.
 */
final class RegistrationAccountExistsException extends ApiHttpException
{
    /**
     * @param Throwable|null $previous
     */
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            ErrorCode::RegistrationAccountExists,
            Response::HTTP_CONFLICT,
            'An account with these details already exists.',
            $previous,
        );
    }
}
