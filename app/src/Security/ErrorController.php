<?php

declare(strict_types=1);

namespace App\Security;

use App\Exception\ApiExceptionInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Custom error controller for handling common HTTP exceptions.
 */
final class ErrorController extends AbstractController
{
    /**
     * @param Throwable $exception
     *
     * @return Response
     */
    public function show(Throwable $exception): Response
    {
        if ($exception instanceof ApiExceptionInterface && $exception instanceof HttpExceptionInterface) {
            return new JsonResponse(
                [
                    'success' => false,
                    'error' => $exception->getErrorCode(),
                    'message' => $exception->getMessage(),
                ],
                $exception->getStatusCode()
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
}
