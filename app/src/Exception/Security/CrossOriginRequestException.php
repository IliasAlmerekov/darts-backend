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
 * Thrown when an unsafe request comes from another origin, or a login lacks a valid CSRF token.
 */
final class CrossOriginRequestException extends ApiHttpException
{
    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            ErrorCode::CrossOriginRequest,
            Response::HTTP_FORBIDDEN,
            'Cross-origin request rejected'
        );
    }
}
