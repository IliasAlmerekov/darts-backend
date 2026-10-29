<?php

/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Enum;

/** Supported checkout rules. */
enum OutRule: string
{
    case Single = 'singleout';
    case Double = 'doubleout';
    case Triple = 'tripleout';

    /**
     * @param int $remaining
     *
     * @return bool
     */
    public function isDeadScore(int $remaining): bool
    {
        return match ($this) {
            self::Single => false,
            self::Double => 1 === $remaining,
            self::Triple => 1 === $remaining || 2 === $remaining,
        };
    }
    /**
     * @param bool $isDouble
     * @param bool $isTriple
     *
     * @return bool
     */
    public function allowsCheckoutWith(bool $isDouble, bool $isTriple): bool
    {
        return match ($this) {
            self::Single => true,
            self::Double => $isDouble,
            self::Triple => $isTriple,
        };
    }

    /**
     * @param int $remaining
     *
     * @return bool
     */
    public function canCheckOutInOneDart(int $remaining): bool
    {
        $double = 50 === $remaining || ($remaining >= 2 && $remaining <= 40 && 0 === $remaining % 2);
        $triple = $remaining >= 3 && $remaining <= 60 && 0 === $remaining % 3;

        return match ($this) {
            self::Single => ($remaining >= 1 && $remaining <= 20) || 25 === $remaining || $double || $triple,
            self::Double => $double,
            self::Triple => $triple,
        };
    }
}
