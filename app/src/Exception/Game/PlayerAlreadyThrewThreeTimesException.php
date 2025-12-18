<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Exception\ApiHttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a player attempts to throw more than 3 times in a round.
 */
final class PlayerAlreadyThrewThreeTimesException extends ApiHttpException
{
    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            'PLAYER_THROWS_LIMIT_REACHED',
            Response::HTTP_BAD_REQUEST,
            'This player has already thrown 3 times in the current round.'
        );
    }
}
