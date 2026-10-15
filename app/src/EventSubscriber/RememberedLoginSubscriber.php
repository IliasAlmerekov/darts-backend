<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\RememberedLoginPolicy;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Decides the passport's remember-me badge after a successful login: on for players, off for everyone else.
 *
 * @psalm-suppress UnusedClass Registered via Symfony service container autoconfiguration
 */
final class RememberedLoginSubscriber implements EventSubscriberInterface
{
    /**
     * @param RememberedLoginPolicy $policy
     *
     * @psalm-suppress PossiblyUnusedMethod Reason: constructor is used by Symfony autowiring.
     */
    public function __construct(private readonly RememberedLoginPolicy $policy)
    {
    }

    /**
     * @return array<string, array{string, int}>
     */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        // After Symfony's request-parameter check (-32), which would enable the badge for an
        // admin who posts `_remember_me=1`, and before the listener that creates the cookie (-64).
        return [LoginSuccessEvent::class => ['onLoginSuccess', -48]];
    }

    /**
     * @param LoginSuccessEvent $event
     *
     * @return void
     */
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $badge = $event->getPassport()->getBadge(RememberMeBadge::class);
        if (!$badge instanceof RememberMeBadge) {
            return;
        }

        if ($this->policy->isRemembered($event->getUser())) {
            $badge->enable();

            return;
        }

        $badge->disable();
    }
}
