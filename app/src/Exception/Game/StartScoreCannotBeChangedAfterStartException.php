<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when attempting to change start score after the game has started.
 */
final class StartScoreCannotBeChangedAfterStartException extends ApiHttpException
{
    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            ErrorCode::GameStartScoreChangeNotAllowed,
            Response::HTTP_CONFLICT,
            'startScore cannot be changed after the game has started.'
        );
    }
}
