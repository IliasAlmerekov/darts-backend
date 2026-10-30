<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\ApiRequest;

use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\InsufficientAuthenticationException;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Returns JSON for API requests and keeps redirect flow for non-API routes.
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
        if (ApiRequest::matches($request)) {
            if ($authException instanceof InsufficientAuthenticationException && $authException->getToken() instanceof RememberMeToken) {
                return new JsonResponse(
                    [
                        'success' => false,
                        'error' => ErrorCode::AccessDenied->value,
                        'message' => 'Forbidden',
                    ],
                    Response::HTTP_FORBIDDEN
                );
            }

            return new JsonResponse(
                [
                    'success' => false,
                    'error' => ErrorCode::AuthenticationRequired->value,
                    'message' => 'Unauthorized',
                ],
                Response::HTTP_UNAUTHORIZED
            );
        }

        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
}
