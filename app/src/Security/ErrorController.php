<?php
namespace App\Security;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ErrorController extends AbstractController
{
    public function show(\Throwable $exception): Response
    {
        if ($exception instanceof NotFoundHttpException) {
            return $this->render('security/error404.html.twig', []);
        }

        if ($exception instanceof AccessDeniedHttpException) {
            return $this->render('security/error403.html.twig', []);
        }

        return new Response('Internal Server Error', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
