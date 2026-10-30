<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\RememberedLoginPolicy;
use App\Security\RememberedLoginTokenPurger;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Decides the passport's remember-me badge after a successful login: on for players, off for everyone else.
 * A player login also purges expired tokens, because the app has no scheduler to do it.
 *
 * @psalm-suppress UnusedClass Registered via Symfony service container autoconfiguration
 */
final class RememberedLoginSubscriber implements EventSubscriberInterface
{
    /**
     * @param RememberedLoginPolicy      $policy
     * @param RememberedLoginTokenPurger $purger
     *
     */
    public function __construct(
        private readonly RememberedLoginPolicy $policy,
        private readonly RememberedLoginTokenPurger $purger,
    ) {
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
            $this->purger->purgeExpired();

            return;
        }

        $badge->disable();
    }
}
