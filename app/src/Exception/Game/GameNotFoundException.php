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
 * Thrown when a game cannot be found.
 */
final class GameNotFoundException extends ApiHttpException
{
    /**
     * The 404 description in the OpenAPI document of every route that takes a game id.
     */
    public const string OPENAPI_DESCRIPTION = 'Kein Spiel hat diese ID (`GAME_NOT_FOUND`).';

    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            ErrorCode::GameNotFound,
            Response::HTTP_NOT_FOUND,
            'Game not found'
        );
    }
}
