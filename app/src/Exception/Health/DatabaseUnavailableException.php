<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Exception\Health;

use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown by the readiness check when the database does not answer.
 *
 * The message is fixed on purpose: the cause can carry the DSN or host, so the caller logs it
 * and does not pass it on.
 */
final class DatabaseUnavailableException extends ApiHttpException
{
    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            ErrorCode::DatabaseUnavailable,
            Response::HTTP_SERVICE_UNAVAILABLE,
            'The database is unavailable.'
        );
    }
}
