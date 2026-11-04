<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ApiExceptionInterface;
use App\Exception\ErrorCode;
use App\Exception\Persistence\ConcurrentChangeException;
use App\Exception\Request\InvalidJsonBodyException;
use App\Http\ViolationCode;
use Doctrine\DBAL\Exception\RetryableException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

/**
 * Custom error controller for handling common HTTP exceptions.
 */
final class ErrorController extends AbstractController
{
    /**
     * @param Throwable    $exception
     * @param Request|null $request
     *
     * @return Response
     */
    public function show(Throwable $exception, ?Request $request = null): Response
    {
        $previous = $exception->getPrevious();
        if ($exception instanceof HttpExceptionInterface && $previous instanceof ValidationFailedException) {
            return $this->validationFailed($exception, $previous);
        }

        // The request payload resolver wraps a body it cannot decode in a 400. Only a JSON
        // body is answered as invalid JSON; another format keeps the generic 400.
        if ($exception instanceof HttpExceptionInterface && $previous instanceof NotEncodableValueException && 'json' === $request?->getContentTypeFormat()) {
            $exception = new InvalidJsonBodyException();
        }

        // A deadlock or lock wait timeout that no retry absorbed (ADR 0004). The client may retry.
        if ($exception instanceof RetryableException) {
            $exception = new ConcurrentChangeException();
        }

        if ($exception instanceof ApiExceptionInterface && $exception instanceof HttpExceptionInterface) {
            return new JsonResponse(
                [
                    'success' => false,
                    'error' => $exception->getErrorCode(),
                    'message' => $exception->getMessage(),
                ] + $exception->getExtraFields(),
                $exception->getStatusCode(),
                $exception->getHeaders()
            );
        }

        $statusCode = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
        $errorCode = match (true) {
            Response::HTTP_BAD_REQUEST === $statusCode => ErrorCode::BadRequest,
            Response::HTTP_UNAUTHORIZED === $statusCode => ErrorCode::AuthenticationRequired,
            Response::HTTP_FORBIDDEN === $statusCode => ErrorCode::AccessDenied,
            Response::HTTP_NOT_FOUND === $statusCode => ErrorCode::NotFound,
            Response::HTTP_METHOD_NOT_ALLOWED === $statusCode => ErrorCode::MethodNotAllowed,
            Response::HTTP_UNSUPPORTED_MEDIA_TYPE === $statusCode => ErrorCode::UnsupportedMediaType,
            $statusCode < Response::HTTP_INTERNAL_SERVER_ERROR => ErrorCode::RequestRejected,
            default => ErrorCode::InternalServerError,
        };

        return new JsonResponse(
            [
                'success' => false,
                'error' => $errorCode->value,
                'message' => Response::$statusTexts[$statusCode] ?? 'HTTP Error',
            ],
            $statusCode
        );
    }

    /**
     * @param HttpExceptionInterface    $exception
     * @param ValidationFailedException $failure
     *
     * @return JsonResponse
     */
    private function validationFailed(HttpExceptionInterface $exception, ValidationFailedException $failure): JsonResponse
    {
        $violations = [];
        foreach ($failure->getViolations() as $violation) {
            $violations[] = [
                'field' => $violation->getPropertyPath(),
                'code' => ViolationCode::of($violation),
                'message' => (string) $violation->getMessage(),
            ];
        }

        return new JsonResponse(
            [
                'success' => false,
                'error' => ErrorCode::RequestValidationFailed->value,
                'message' => 'The request body failed validation.',
                'violations' => $violations,
            ],
            $exception->getStatusCode(),
            $exception->getHeaders()
        );
    }
}
