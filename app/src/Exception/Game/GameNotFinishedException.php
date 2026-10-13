<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Enum\GameStatus;
use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a finished-game summary is requested for a game that has not finished.
 */
final class GameNotFinishedException extends ApiHttpException
{
    /**
     * @param GameStatus $status
     *
     * @return void
     */
    public function __construct(GameStatus $status)
    {
        parent::__construct(
            ErrorCode::GameNotFinished,
            Response::HTTP_CONFLICT,
            sprintf('Game summary is only available for finished games. Current status: %s.', $status->value)
        );
    }
}
