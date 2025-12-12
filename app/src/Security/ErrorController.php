<?php

namespace App\Security;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
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
        if ($exception instanceof NotFoundHttpException) {
            return new JsonResponse(['success' => false, 'message' => 'Not Found'], Response::HTTP_NOT_FOUND);
        }

        if ($exception instanceof AccessDeniedHttpException) {
            return new JsonResponse(['success' => false, 'message' => 'Access Denied'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse(['success' => false, 'message' => 'Internal Server Error'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
