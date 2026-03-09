<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Enum\GameStatus;
use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a throw is attempted in an invalid game state.
 */
final class GameThrowNotAllowedException extends ApiHttpException
{
    /**
     * @param GameStatus $status
     *
     * @return void
     */
    public function __construct(GameStatus $status)
    {
        parent::__construct(
            ErrorCode::GameThrowNotAllowed,
            Response::HTTP_CONFLICT,
            sprintf('Throws can only be recorded while the game is started. Current status: %s.', $status->value)
        );
    }
}
