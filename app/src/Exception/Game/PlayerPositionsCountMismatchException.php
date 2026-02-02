<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when provided player positions do not match the player count in the game.
 */
final class PlayerPositionsCountMismatchException extends ApiHttpException
{
    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            ErrorCode::GamePlayerPositionsCountMismatch,
            Response::HTTP_BAD_REQUEST,
            'Player positions count must match players in game.'
        );
    }
}
