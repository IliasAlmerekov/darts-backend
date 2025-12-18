<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Exception\ApiHttpException;
use Symfony\Component\HttpFoundation\Response;

final class PlayerAlreadyThrewThreeTimesException extends ApiHttpException
{
    public function __construct()
    {
        parent::__construct(
            'PLAYER_THROWS_LIMIT_REACHED',
            Response::HTTP_BAD_REQUEST,
            'This player has already thrown 3 times in the current round.'
        );
    }
}

