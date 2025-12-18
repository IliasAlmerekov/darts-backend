<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Exception\Game;

use App\Exception\ApiHttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a game cannot be found.
 */
final class GameNotFoundException extends ApiHttpException
{
    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            'GAME_NOT_FOUND',
            Response::HTTP_NOT_FOUND,
            'Game not found'
        );
    }
}
