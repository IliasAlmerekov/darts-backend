<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when the provided start score is not allowed.
 */
final class InvalidStartScoreException extends ApiHttpException
{
    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            ErrorCode::GameInvalidStartScore,
            Response::HTTP_BAD_REQUEST,
            'startScore must be one of: 101, 201, 301, 401, 501.'
        );
    }
}
