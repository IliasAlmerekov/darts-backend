<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a player tries to join a room that already reached max capacity.
 */
final class GameRoomFullException extends ApiHttpException
{
    /**
     * @param int $maxPlayers
     *
     * @return void
     */
    public function __construct(int $maxPlayers)
    {
        parent::__construct(
            ErrorCode::GameRoomFull,
            Response::HTTP_CONFLICT,
            sprintf('Game room is full. Maximum %d players allowed.', $maxPlayers)
        );
    }
}
