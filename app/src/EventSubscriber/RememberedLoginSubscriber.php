<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\RememberedLoginPolicy;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Turns on the passport's remember-me badge for players after a successful login.
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
     * @return array<string, string>
     */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onLoginSuccess'];
    }

    /**
     * @param LoginSuccessEvent $event
     *
     * @return void
     */
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if (!$this->policy->isRemembered($event->getUser())) {
            return;
        }

        $badge = $event->getPassport()->getBadge(RememberMeBadge::class);
        if ($badge instanceof RememberMeBadge) {
            $badge->enable();
        }
    }
}
