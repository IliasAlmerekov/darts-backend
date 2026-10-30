<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Http\ViolationCode;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Reports the constraint violations of a payload next to its UNKNOWN_FIELD violations (#90).
 *
 * The request payload resolver skips validation as soon as the serializer reports a violation,
 * so a body with an unknown key and an invalid known value would list only the unknown key.
 * Symfony keeps the mapped object in the failure, so this validates it and adds the result.
 * A body with another serializer violation, such as a wrong type, keeps the resolver's answer:
 * its object is only partly mapped.
 *
 * shortcut: validates with the default group; no endpoint sets validationGroups, add them
 * here when one does.
 *
 * @psalm-suppress UnusedClass Registered via Symfony service container autoconfiguration
 */
final readonly class ValidatePayloadNextToUnknownFieldsSubscriber implements EventSubscriberInterface
{
    /**
     * @param ValidatorInterface $validator
     *
     */
    public function __construct(private ValidatorInterface $validator)
    {
    }

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
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $failure = $exception->getPrevious();
        if (!$exception instanceof HttpException || !$failure instanceof ValidationFailedException || !is_object($failure->getValue())) {
            return;
        }

        foreach ($failure->getViolations() as $violation) {
            // A violation with a constraint comes from the validator, which already ran.
            if (null !== $violation->getConstraint() || ViolationCode::UNKNOWN_FIELD !== ViolationCode::of($violation)) {
                return;
            }
        }

        $violations = new ConstraintViolationList();
        $violations->addAll($failure->getViolations());
        $violations->addAll($this->validator->validate($failure->getValue()));

        $event->setThrowable(new HttpException(
            $exception->getStatusCode(),
            $exception->getMessage(),
            new ValidationFailedException($failure->getValue(), $violations),
            $exception->getHeaders(),
        ));
    }
}
