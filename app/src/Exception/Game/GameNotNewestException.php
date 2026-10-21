<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Exception\Game;

use App\Enum\GameStatus;
use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when an action that could abort the game in progress targets a game that is not the
 * newest game, or the newest game in a status the action does not accept (ADR 0005).
 */
final class GameNotNewestException extends ApiHttpException
{
    /**
     * @param list<GameStatus> $allowedStatuses
     *
     * @return void
     */
    public function __construct(array $allowedStatuses)
    {
        parent::__construct(
            ErrorCode::GameNotNewest,
            Response::HTTP_CONFLICT,
            sprintf(
                'This action is allowed only on the newest game, when it is %s.',
                implode(' or ', array_map(static fn (GameStatus $status): string => $status->value, $allowedStatuses))
            )
        );
    }
}
