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
 * Symfony's default logout listener only sets a redirect when the event has no response, and
 * the cookie-clearing listeners run after this one, so the remember-me and session cookies
 * still go out on the 204.
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
