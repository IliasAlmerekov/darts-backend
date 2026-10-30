<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;

/** Identifies API requests by path or Accept header. */
final class ApiRequest
{
    /**
     * @param Request $request
     *
     * @return bool
     */
    public static function matches(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api/')
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json');
    }
}
