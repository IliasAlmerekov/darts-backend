<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Exception\Game;

use App\Enum\GameStatus;
use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a game cannot be joined in its current state.
 */
final class GameJoinNotAllowedException extends ApiHttpException
{
    /**
     * @param GameStatus $status
     *
     * @return void
     */
    public function __construct(GameStatus $status)
    {
        parent::__construct(
            ErrorCode::GameJoinNotAllowed,
            Response::HTTP_CONFLICT,
            sprintf('Game cannot be joined while it is %s.', $status->value)
        );
    }
}
