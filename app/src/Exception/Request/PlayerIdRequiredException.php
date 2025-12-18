<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Exception\Request;

use App\Exception\ApiHttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a request requires a playerId but none is provided.
 */
final class PlayerIdRequiredException extends ApiHttpException
{
    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            'PLAYER_ID_REQUIRED',
            Response::HTTP_BAD_REQUEST,
            'Player ID is required'
        );
    }
}
