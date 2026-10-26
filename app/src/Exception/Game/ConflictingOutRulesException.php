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
 * Thrown when a request would leave a game with double out and triple out at once.
 */
final class ConflictingOutRulesException extends ApiHttpException
{
    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            ErrorCode::GameConflictingOutRules,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'doubleOut and tripleOut cannot both be true.'
        );
    }

    /**
     * Rejects the out rule a game would end up with, before anything is changed.
     *
     * @param bool $doubleOut
     * @param bool $tripleOut
     *
     * @return void
     */
    public static function assertCompatible(bool $doubleOut, bool $tripleOut): void
    {
        if ($doubleOut && $tripleOut) {
            throw new self();
        }
    }
}
