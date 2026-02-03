<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Exception\Security;

use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when the current user is not allowed to access a resource.
 */
final class SecurityAccessDeniedException extends ApiHttpException
{
    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            ErrorCode::SecurityAccessDenied,
            Response::HTTP_FORBIDDEN,
            'Access denied'
        );
    }
}
