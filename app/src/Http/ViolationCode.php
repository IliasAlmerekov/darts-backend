<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Maps a validation violation to the code the client reads (ADR 0006).
 */
final class ViolationCode
{
    public const string UNKNOWN_FIELD = 'UNKNOWN_FIELD';

    /**
     * Templates of the violations the request payload resolver adds, without a constraint,
     * for a value the serializer cannot map to the property's type.
     */
    private const array TYPE_TEMPLATES = [
        'This value should be of type {{ type }}.',
        'This value was of an unexpected type.',
    ];

    /**
     * Template of the violation the request payload resolver adds, without a constraint,
     * for each key the DTO does not declare.
     */
    private const string UNKNOWN_FIELD_TEMPLATE = 'This attribute was not expected.';

    /**
     * @param ConstraintViolationInterface $violation
     *
     * @return string
     */
    public static function of(ConstraintViolationInterface $violation): string
    {
        $constraint = $violation->getConstraint();
        if (null === $constraint) {
            $template = $violation->getMessageTemplate();

            return match (true) {
                self::UNKNOWN_FIELD_TEMPLATE === $template => self::UNKNOWN_FIELD,
                in_array($template, self::TYPE_TEMPLATES, true) => 'INVALID_TYPE',
                default => 'INVALID',
            };
        }

        $payloadCode = is_array($constraint->payload) ? ($constraint->payload['code'] ?? null) : null;
        if (is_string($payloadCode)) {
            return $payloadCode;
        }

        if ($constraint instanceof Assert\Length) {
            $parameters = $violation->getParameters();
            if (!isset($parameters['{{ value_length }}'], $parameters['{{ limit }}'])) {
                return 'INVALID';
            }

            return (int) $parameters['{{ value_length }}'] > (int) $parameters['{{ limit }}'] ? 'TOO_LONG' : 'TOO_SHORT';
        }

        if ($constraint instanceof Assert\Collection) {
            if (Assert\Collection::MISSING_FIELD_ERROR === $violation->getCode()) {
                return 'REQUIRED';
            }
            if (Assert\Collection::NO_SUCH_FIELD_ERROR === $violation->getCode()) {
                return self::UNKNOWN_FIELD;
            }
        }

        // Nested constraints (Sequentially, All, Collection) never get here for a nested
        // failure: its violation carries the inner constraint.
        return match (true) {
            $constraint instanceof Assert\NotBlank, $constraint instanceof Assert\NotNull => 'REQUIRED',
            $constraint instanceof Assert\Email => 'INVALID_EMAIL',
            $constraint instanceof Assert\Choice => 'INVALID_CHOICE',
            $constraint instanceof Assert\Range, $constraint instanceof Assert\Positive, $constraint instanceof Assert\PositiveOrZero, $constraint instanceof Assert\Count => 'OUT_OF_RANGE',
            $constraint instanceof Assert\Type => 'INVALID_TYPE',
            $constraint instanceof Assert\Unique => 'DUPLICATE',
            default => 'INVALID',
        };
    }
}
