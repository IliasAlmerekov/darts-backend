<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Exception\Game;

use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a seat is added, removed or reordered outside the lobby.
 */
final class SeatsNotEditableException extends ApiHttpException
{
    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            ErrorCode::GameSeatsNotEditable,
            Response::HTTP_CONFLICT,
            'Seats can only be changed while the game is in the lobby.'
        );
    }
}
