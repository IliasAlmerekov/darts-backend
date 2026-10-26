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
 * Thrown when attempting to change the out rule after the game has its first throw.
 */
final class OutRuleCannotBeChangedAfterFirstThrowException extends ApiHttpException
{
    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            ErrorCode::GameOutRuleChangeNotAllowed,
            Response::HTTP_CONFLICT,
            'The out rule cannot be changed after the first throw.'
        );
    }
}
