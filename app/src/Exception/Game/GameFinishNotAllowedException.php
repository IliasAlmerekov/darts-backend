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
 * Thrown when a game cannot be finished in its current state.
 */
final class GameFinishNotAllowedException extends ApiHttpException
{
    /**
     * @param GameStatus $status
     *
     * @return void
     */
    public function __construct(GameStatus $status)
    {
        parent::__construct(
            ErrorCode::GameFinishNotAllowed,
            Response::HTTP_CONFLICT,
            sprintf('Game cannot be finished from status %s.', $status->value)
        );
    }
}
