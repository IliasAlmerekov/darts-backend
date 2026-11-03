<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

/** The configured avatar directory cannot be read. */
final class AvatarDirectoryUnavailableException extends ApiHttpException
{
    /** Creates the service-unavailable error. */
    public function __construct()
    {
        parent::__construct(ErrorCode::AvatarDirectoryUnavailable, Response::HTTP_SERVICE_UNAVAILABLE, 'Avatar directory unavailable.');
    }
}
