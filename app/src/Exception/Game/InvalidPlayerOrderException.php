<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when player order payload contains duplicate or conflicting entries.
 */
final class InvalidPlayerOrderException extends ApiHttpException
{
    /**
     * @param string $message
     *
     * @return void
     */
    public function __construct(string $message)
    {
        parent::__construct(
            ErrorCode::GameInvalidPlayerOrder,
            Response::HTTP_BAD_REQUEST,
            $message
        );
    }
}
