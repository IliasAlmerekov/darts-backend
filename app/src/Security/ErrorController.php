<?php

declare(strict_types=1);

namespace App\Security;

use App\Exception\ApiExceptionInterface;
use App\Exception\ErrorCode;
use App\Exception\Request\InvalidJsonBodyException;
use App\Http\ViolationCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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

        if ($exception instanceof ApiExceptionInterface && $exception instanceof HttpExceptionInterface) {
            return new JsonResponse(
                [
                    'success' => false,
                    'error' => $exception->getErrorCode(),
                    'message' => $exception->getMessage(),
                ],
                $exception->getStatusCode(),
                $exception->getHeaders()
            );
        }

        if ($exception instanceof NotFoundHttpException) {
            return new JsonResponse(
                [
                    'success' => false,
                    'error' => 'NOT_FOUND',
                    'message' => 'Not Found',
                ],
                Response::HTTP_NOT_FOUND
            );
        }

        if ($exception instanceof HttpExceptionInterface) {
            $statusCode = $exception->getStatusCode();
            $statusText = Response::$statusTexts[$statusCode] ?? 'HTTP Error';
            $errorCode = match ($statusCode) {
                Response::HTTP_FORBIDDEN => 'ACCESS_DENIED',
                Response::HTTP_METHOD_NOT_ALLOWED => 'METHOD_NOT_ALLOWED',
                default => 'HTTP_ERROR',
            };

            return new JsonResponse(
                [
                    'success' => false,
                    'error' => $errorCode,
                    'message' => $statusText,
                ],
                $statusCode
            );
        }

        return new JsonResponse(
            [
                'success' => false,
                'error' => 'INTERNAL_SERVER_ERROR',
                'message' => 'Internal Server Error',
            ],
            Response::HTTP_INTERNAL_SERVER_ERROR
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
