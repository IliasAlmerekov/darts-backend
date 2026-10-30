<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\Security\CrossOriginRequestException;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Rejects unsafe /api requests that come from another origin (docs/adr/0007).
 *
 * `Sec-Fetch-Site` decides when present: only `same-origin` passes. Without it, an `Origin`
 * must equal the request's own scheme and host. A request with neither header passes, since
 * browsers send at least one on every unsafe request and server-side clients send none.
 *
 * It runs on kernel.request at priority 16: after the router (32), before the firewall (8).
 * A hostile request is refused before authentication and login throttling, and this check
 * touches neither the database nor the session. It covers /api/login too, so a foreign-origin
 * login never reaches the throttler. The login token check is separate (form_login,
 * SameOriginCsrfTokenManager): it runs after the throttler and may use the session.
 *
 * @psalm-suppress UnusedClass Registered via Symfony service container autoconfiguration
 */
final readonly class CrossOriginRequestSubscriber implements EventSubscriberInterface
{
    private const array UNSAFE_METHODS = [
        Request::METHOD_POST,
        Request::METHOD_PUT,
        Request::METHOD_PATCH,
        Request::METHOD_DELETE,
    ];

    /**
     * @return array<string, array{string, int}>
     */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 16],
        ];
    }

    /**
     * @param RequestEvent $event
     *
     * @return void
     *
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !in_array($request->getMethod(), self::UNSAFE_METHODS, true)) {
            return;
        }
        $path = $request->getPathInfo();
        if ('/api' !== $path && !str_starts_with($path, '/api/')) {
            return;
        }

        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        $origin = $request->headers->get('Origin');
        $sameOrigin = match (true) {
            null !== $fetchSite => 'same-origin' === $fetchSite,
            null !== $origin => $origin === $request->getSchemeAndHttpHost(),
            default => true,
        };

        if (!$sameOrigin) {
            throw new CrossOriginRequestException();
        }
    }
}
