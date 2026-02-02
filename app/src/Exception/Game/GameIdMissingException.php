<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a game entity is missing an identifier.
 */
final class GameIdMissingException extends ApiHttpException
{
    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            ErrorCode::GameIdMissing,
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'Game ID is missing.'
        );
    }
}
