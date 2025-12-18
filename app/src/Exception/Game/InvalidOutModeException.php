<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Exception\ApiHttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when an unsupported out mode is provided.
 */
final class InvalidOutModeException extends ApiHttpException
{
    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            'INVALID_OUT_MODE',
            Response::HTTP_BAD_REQUEST,
            'outMode must be one of: singleout, doubleout, tripleout.'
        );
    }
}
