<?php

declare(strict_types=1);

namespace App\Exception;

use Override;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Base exception for API errors that must be rendered as JSON responses.
 */
class ApiHttpException extends HttpException implements ApiExceptionInterface
{
    public function __construct(
        private readonly string $errorCode,
        int $statusCode,
        string $message = '',
        ?Throwable $previous = null,
        array $headers = [],
        int $code = 0
    ) {
        parent::__construct($statusCode, $message, $previous, $headers, $code);
    }

    #[Override]
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
