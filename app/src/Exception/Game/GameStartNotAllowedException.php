<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Enum\GameStatus;
use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a game start is attempted in an invalid game state.
 */
final class GameStartNotAllowedException extends ApiHttpException
{
    /**
     * @param GameStatus $status
     *
     * @return void
     */
    public function __construct(GameStatus $status)
    {
        parent::__construct(
            ErrorCode::GameStartNotAllowed,
            Response::HTTP_CONFLICT,
            sprintf('Game can only be started from lobby status. Current status: %s.', $status->value)
        );
    }
}
