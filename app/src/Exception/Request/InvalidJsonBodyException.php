<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Exception\Request;

use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when the request body is not a valid JSON object/array.
 */
final class InvalidJsonBodyException extends ApiHttpException
{
    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            ErrorCode::RequestInvalidJsonBody,
            Response::HTTP_BAD_REQUEST,
            'Ungültiger JSON-Body. Bitte überprüfe das Datenformat.'
        );
    }
}
