<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Exception\Persistence;

use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers a transaction that MySQL rolled back as a deadlock victim or after a lock wait
 * timeout. ErrorController builds it from Doctrine's RetryableException, so every write path
 * answers 409 instead of the generic 500 and the client can retry.
 */
final class ConcurrentChangeException extends ApiHttpException
{
    /**
     * Why the 409 happens, for routes whose retry advice differs from OPENAPI_DESCRIPTION.
     */
    public const string OPENAPI_CAUSE = 'Eine parallele Anfrage hat dieselben Daten gesperrt, und MySQL hat die Transaktion als Deadlock oder nach einem Lock-Wait-Timeout zurückgerollt (`CONCURRENT_CHANGE`).';

    /**
     * The 409 description in the OpenAPI document of every route that writes in a transaction.
     */
    public const string OPENAPI_DESCRIPTION = self::OPENAPI_CAUSE.' Der Request kann wiederholt werden.';

    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            ErrorCode::ConcurrentChange,
            Response::HTTP_CONFLICT,
            'A concurrent request changed the same data. Retry the request.'
        );
    }
}
