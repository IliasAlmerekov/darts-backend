<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Controller\RegistrationController;
use App\Exception\Request\InvalidJsonBodyException;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Renders the registration payload failures in the registration error format.
 *
 * Invalid fields answer 400 with the field errors per field. A body that cannot be decoded
 * as JSON answers like InvalidJsonBodyException. Other DTO endpoints keep the default 422
 * handling.
 *
 * @psalm-suppress UnusedClass Registered via Symfony service container autoconfiguration
 */
final class RegistrationErrorSubscriber implements EventSubscriberInterface
{
    /**
     * @return array<string, string>
     */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onKernelException',
        ];
    }

    /**
     * @param ExceptionEvent $event
     *
     * @return void
     *
     * @psalm-suppress PossiblyUnusedMethod Reason: Symfony event dispatcher invokes this listener.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || RegistrationController::ROUTE !== $event->getRequest()->attributes->get('_route')) {
            return;
        }

        $previous = $event->getThrowable()->getPrevious();
        if ($previous instanceof ValidationFailedException) {
            $event->setResponse(new JsonResponse(
                [
                    'success' => false,
                    'message' => 'Registrierung fehlgeschlagen. Bitte überprüfe deine Eingaben.',
                    'errors' => $this->collectErrors($previous),
                ],
                Response::HTTP_BAD_REQUEST,
            ));

            return;
        }

        // The payload resolver wraps a body it cannot decode in a 400.
        if ($previous instanceof NotEncodableValueException) {
            $event->setThrowable(new InvalidJsonBodyException());
        }
    }

    /**
     * @param ValidationFailedException $exception
     *
     * @return array<string, list<string>>
     */
    private function collectErrors(ValidationFailedException $exception): array
    {
        $errors = [];
        foreach ($exception->getViolations() as $violation) {
            $field = $violation->getPropertyPath();
            $errors['' === $field ? 'global' : $field][] = (string) $violation->getMessage();
        }

        return $errors;
    }
}
