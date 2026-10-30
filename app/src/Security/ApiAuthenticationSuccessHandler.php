<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\ApiRequest;

use App\Entity\User;
use App\Exception\Security\UserNotAuthenticatedException;
use App\Service\Security\SecurityServiceInterface;
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
     * @param LoginSuccessResponder    $responder
     * @param UrlGeneratorInterface    $urlGenerator
     */
    public function __construct(
        private SecurityServiceInterface $securityService,
        private LoginSuccessResponder $responder,
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
        if (ApiRequest::matches($request)) {
            $user = $token->getUser();

            if (!$user instanceof User) {
                throw new UserNotAuthenticatedException();
            }

            $invitedGameId = InvitationSession::take($request->getSession());

            return $this->responder->respond($this->securityService->resolveLoginSuccess($user, $invitedGameId));
        }

        return new RedirectResponse($this->urlGenerator->generate('login_success'));
    }
}
