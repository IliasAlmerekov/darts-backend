<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a throw is attempted by a player that is not active in the current round.
 */
final class GamePlayerNotActiveException extends ApiHttpException
{
    /**
     * @param int      $requestedPlayerId
     * @param int|null $activePlayerId
     *
     * @return void
     */
    public function __construct(int $requestedPlayerId, ?int $activePlayerId)
    {
        $message = null === $activePlayerId
            ? sprintf('No active player available in current round. Requested player id: %d.', $requestedPlayerId)
            : sprintf('It is not player %d turn. Active player id: %d.', $requestedPlayerId, $activePlayerId);

        parent::__construct(
            ErrorCode::GamePlayerNotActive,
            Response::HTTP_CONFLICT,
            $message
        );
    }
}
