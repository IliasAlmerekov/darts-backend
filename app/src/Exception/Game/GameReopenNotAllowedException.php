<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Enum\GameStatus;
use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a game cannot be reopened in its current state.
 */
final class GameReopenNotAllowedException extends ApiHttpException
{
    /**
     * @param GameStatus $status
     *
     * @return void
     */
    public function __construct(GameStatus $status)
    {
        parent::__construct(
            ErrorCode::GameReopenNotAllowed,
            Response::HTTP_CONFLICT,
            sprintf('Game can only be reopened from finished status. Current status: %s.', $status->value)
        );
    }
}
