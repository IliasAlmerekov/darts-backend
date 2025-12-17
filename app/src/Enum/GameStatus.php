<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * This enum represents the status of a game.
 */
enum GameStatus: string
{
    case Lobby = 'lobby';
    case Started = 'started';
    case Finished = 'finished';
    case Aborted = 'aborted';
}
