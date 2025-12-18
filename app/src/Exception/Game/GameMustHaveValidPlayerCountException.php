<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Exception\ApiHttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a game has an invalid number of players for the requested action.
 */
final class GameMustHaveValidPlayerCountException extends ApiHttpException
{
    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            'GAME_INVALID_PLAYER_COUNT',
            Response::HTTP_BAD_REQUEST,
            'Game must have between 2 and 10 players to start.'
        );
    }
}
