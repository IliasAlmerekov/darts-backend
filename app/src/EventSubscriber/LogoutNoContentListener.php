<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Answers POST /api/logout with an empty 204 instead of Symfony's redirect to `/`.
 *
 * Symfony's DefaultLogoutListener runs at priority 64 and sets the redirect first. This listener
 * runs at priority 0 and replaces that response with the 204. The CookieClearingLogoutListener
 * runs at priority -255, so the remember-me and session cookies are still cleared on the 204.
 *
 * @psalm-suppress UnusedClass Registered through the AsEventListener attribute
 */
#[AsEventListener(event: LogoutEvent::class, dispatcher: 'security.event_dispatcher.main')]
final class LogoutNoContentListener
{
    /**
     * @param LogoutEvent $event
     *
     * @return void
     *
     * @psalm-suppress PossiblyUnusedMethod Called by the event dispatcher
     */
    public function __invoke(LogoutEvent $event): void
    {
        $event->setResponse(new Response(null, Response::HTTP_NO_CONTENT));
    }
}
