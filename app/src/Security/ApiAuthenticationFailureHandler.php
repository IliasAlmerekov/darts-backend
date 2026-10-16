<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Returns JSON for API authentication failures instead of browser-style redirects.
 *
 * @psalm-suppress UnusedClass Wired via Symfony security configuration
 */
final readonly class ApiAuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    /**
     * @param UrlGeneratorInterface $urlGenerator
     */
    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
    }

    /**
     * @param Request                 $request
     * @param AuthenticationException $exception
     *
     * @return Response
     */
    #[\Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($this->isApiRequest($request)) {
            $tooManyAttempts = $exception instanceof TooManyLoginAttemptsAuthenticationException;
            $status = $tooManyAttempts ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_UNAUTHORIZED;
            $response = new JsonResponse([
                'success' => false,
                'last_username' => (string) $request->request->get('_username', ''),
                // The throttling exception keeps %minutes% in its message; fill it in.
                'error' => $tooManyAttempts
                    ? strtr($exception->getMessageKey(), $exception->getMessageData())
                    : $exception->getMessage(),
                'status' => $status,
            ], $status);

            if ($tooManyAttempts) {
                $minutes = $exception->getMessageData()['%minutes%'];
                if (is_int($minutes) && $minutes > 0) {
                    $response->headers->set('Retry-After', (string) ($minutes * 60));
                }
            }

            return $response;
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
