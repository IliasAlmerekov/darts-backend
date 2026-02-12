<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a throw payload is syntactically valid JSON but represents an invalid dart throw.
 */
final class InvalidThrowException extends ApiHttpException
{
    /**
     * @param string $message
     *
     * @return void
     */
    public function __construct(string $message)
    {
        parent::__construct(
            ErrorCode::GameInvalidThrow,
            Response::HTTP_BAD_REQUEST,
            $message
        );
    }
}
