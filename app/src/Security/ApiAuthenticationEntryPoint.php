<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Returns JSON 401 for API requests and keeps redirect flow for non-API routes.
 */
final readonly class ApiAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    /**
     * @param UrlGeneratorInterface $urlGenerator
     */
    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
    }

    /**
     * @param Request                      $request
     * @param AuthenticationException|null $authException
     *
     * @return Response
     */
    #[\Override]
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if ($this->isApiRequest($request)) {
            return new JsonResponse(
                [
                    'success' => false,
                    'error' => 'AUTHENTICATION_REQUIRED',
                    'message' => 'Unauthorized',
                ],
                Response::HTTP_UNAUTHORIZED
            );
        }

        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }

    /**
     * @param Request $request
     *
     * @return bool
     */
    private function isApiRequest(Request $request): bool
    {
        if (str_starts_with($request->getPathInfo(), '/api/')) {
            return true;
        }

        $accept = (string) $request->headers->get('Accept', '');

        return str_contains($accept, 'application/json');
    }
}
