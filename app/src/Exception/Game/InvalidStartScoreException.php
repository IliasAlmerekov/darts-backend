<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Exception\ApiHttpException;
use Symfony\Component\HttpFoundation\Response;

final class InvalidStartScoreException extends ApiHttpException
{
    public function __construct()
    {
        parent::__construct(
            'INVALID_START_SCORE',
            Response::HTTP_BAD_REQUEST,
            'startScore must be one of: 101, 201, 301, 401, 501.'
        );
    }
}

