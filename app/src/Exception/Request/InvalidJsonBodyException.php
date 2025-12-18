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
            'INVALID_JSON_BODY',
            Response::HTTP_BAD_REQUEST,
            'Ungültiger JSON-Body. Bitte überprüfe das Datenformat.'
        );
    }
}
