<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Exception\ApiHttpException;
use Symfony\Component\HttpFoundation\Response;

final class PlayerPositionsCountMismatchException extends ApiHttpException
{
    public function __construct()
    {
        parent::__construct(
            'PLAYER_POSITIONS_COUNT_MISMATCH',
            Response::HTTP_BAD_REQUEST,
            'Player positions count must match players in game.'
        );
    }
}

