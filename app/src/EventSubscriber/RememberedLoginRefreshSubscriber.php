<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\RememberedLoginPolicy;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;
use Symfony\Component\Security\Http\RememberMe\ResponseListener;

/**
 * Keeps a player's remember-me token and cookie fresh while the PHP session is still valid.
 *
 * Symfony only refreshes the token when the remember-me authenticator restores a login.
 * A player whose session stays alive would otherwise never move `lastUsed` forward, and
 * would be logged out 7 days after the last restored login instead of the last visit.
 *
 * @psalm-suppress UnusedClass Registered via Symfony service container autoconfiguration
 */
final class RememberedLoginRefreshSubscriber implements EventSubscriberInterface
{
    /**
     * @param TokenStorageInterface      $tokenStorage
     * @param RememberMeHandlerInterface $rememberMeHandler
     * @param RememberedLoginPolicy      $policy
     * @param string                     $cookieName
     *
     * @psalm-suppress PossiblyUnusedMethod Reason: constructor is used by Symfony autowiring.
     */
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RememberMeHandlerInterface $rememberMeHandler,
        private readonly RememberedLoginPolicy $policy,
        private readonly string $cookieName,
    ) {
    }

    /**
     * @return array<string, array{string, int}>
     */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        // Before the security ResponseListener (priority 0) attaches the cookie.
        return [KernelEvents::RESPONSE => ['onKernelResponse', 10]];
    }

    /**
     * @param ResponseEvent $event
     *
     * @return void
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $rawCookie = $request->cookies->get($this->cookieName);
        if (!$event->isMainRequest() || !is_string($rawCookie) || $request->attributes->has(ResponseListener::COOKIE_ATTR_NAME)) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();
        if (null === $token || $token instanceof RememberMeToken || null === $user || !$this->policy->isRemembered($user)) {
            return;
        }

        try {
            $details = RememberMeDetails::fromRawCookie($rawCookie);
            if ($details->getUserIdentifier() === $user->getUserIdentifier()) {
                $this->rememberMeHandler->consumeRememberMeCookie($details);
            }
        } catch (AuthenticationException) {
            // A stale or foreign cookie must not fail a request the session already authenticated.
        }
    }
}
