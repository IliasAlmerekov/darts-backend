<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gives the reissued remember-me cookie a fresh lifetime, so 7 days count from the last visit.
 *
 * Symfony's persistent handler keeps the cookie's expiry at the previous visit plus the
 * lifetime, while the token row in the database already slides. Without this the browser
 * drops the cookie earlier than the server would reject it.
 *
 * @psalm-suppress UnusedClass Registered via Symfony service container autoconfiguration
 */
final class RememberedLoginCookieSubscriber implements EventSubscriberInterface
{
    /**
     * @param string $cookieName
     * @param int    $lifetime
     *
     * @psalm-suppress PossiblyUnusedMethod Reason: constructor is used by Symfony autowiring.
     */
    public function __construct(
        private readonly string $cookieName,
        private readonly int $lifetime,
    ) {
    }

    /**
     * @return array<string, array{string, int}>
     */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        // After the security ResponseListener (priority 0) has attached the cookie.
        return [KernelEvents::RESPONSE => ['onKernelResponse', -10]];
    }

    /**
     * @param ResponseEvent $event
     *
     * @return void
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        foreach ($headers->getCookies() as $cookie) {
            if ($cookie->getName() !== $this->cookieName || null === $cookie->getValue()) {
                continue;
            }

            $headers->setCookie($this->withFreshLifetime($cookie));
        }
    }

    /**
     * @param Cookie $cookie
     *
     * @return Cookie
     */
    private function withFreshLifetime(Cookie $cookie): Cookie
    {
        return $cookie->withExpires(time() + $this->lifetime);
    }
}
