<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Controller\RegistrationController;
use App\Exception\Registration\RegistrationRateLimitedException;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Counts every registration request against the per-IP limit.
 *
 * It runs on kernel.controller, before #[MapRequestPayload] maps and validates the body,
 * so requests with malformed JSON or invalid fields count too.
 *
 * @psalm-suppress UnusedClass Registered via Symfony service container autoconfiguration
 */
final readonly class RegistrationRateLimitSubscriber implements EventSubscriberInterface
{
    /**
     * @param RateLimiterFactoryInterface $registrationLimiter
     *
     */
    public function __construct(private RateLimiterFactoryInterface $registrationLimiter)
    {
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }

    /**
     * @param ControllerEvent $event
     *
     * @return void
     *
     */
    public function onKernelController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || RegistrationController::ROUTE !== $request->attributes->get('_route')) {
            return;
        }

        $rateLimit = $this->registrationLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$rateLimit->isAccepted()) {
            throw new RegistrationRateLimitedException($rateLimit->getRetryAfter());
        }
    }
}
