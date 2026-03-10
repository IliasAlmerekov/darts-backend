<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Service\Security\SecurityServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * Returns JSON for API authentication success instead of browser-style redirects.
 *
 * @psalm-suppress UnusedClass Wired via Symfony security configuration
 */
final readonly class ApiAuthenticationSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    /**
     * @param SecurityServiceInterface $securityService
     * @param UrlGeneratorInterface    $urlGenerator
     */
    public function __construct(
        private SecurityServiceInterface $securityService,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param Request        $request
     * @param TokenInterface $token
     *
     * @return Response
     */
    #[\Override]
    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        if ($this->isApiRequest($request)) {
            $user = $token->getUser();

            if (!$user instanceof User) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'USER_NOT_AUTHENTICATED',
                    'message' => 'User not authenticated',
                ], Response::HTTP_UNAUTHORIZED);
            }

            return $this->securityService->buildLoginSuccessResponse($user, $request->getSession());
        }

        return new RedirectResponse($this->urlGenerator->generate('login_success'));
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
