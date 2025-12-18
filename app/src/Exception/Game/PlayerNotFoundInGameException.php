<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Exception\ApiHttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a player cannot be found in the given game.
 */
final class PlayerNotFoundInGameException extends ApiHttpException
{
    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            'PLAYER_NOT_FOUND',
            Response::HTTP_NOT_FOUND,
            'Player not found in this game'
        );
    }
}
