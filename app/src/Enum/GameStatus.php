<?php

namespace App\Enum;

enum GameStatus: string
{
    case Lobby = 'lobby';
    case Started = 'started';
    case Finished = 'finished';
}
