<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Exception\ApiHttpException;
use Symfony\Component\HttpFoundation\Response;

final class StartScoreCannotBeChangedAfterStartException extends ApiHttpException
{
    public function __construct()
    {
        parent::__construct(
            'START_SCORE_CHANGE_NOT_ALLOWED',
            Response::HTTP_CONFLICT,
            'startScore cannot be changed after the game has started.'
        );
    }
}

