<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

/**
 * A dart's value as the API shows it.
 */
final class DartThrow
{
    /**
     * Turns a stored (multiplied) value back into the board segment value it was thrown at.
     *
     * @param int  $storedValue
     * @param bool $isDouble
     * @param bool $isTriple
     *
     * @return int
     */
    public static function baseValue(int $storedValue, bool $isDouble, bool $isTriple): int
    {
        if ($isTriple && 0 === $storedValue % 3) {
            $baseValue = intdiv($storedValue, 3);
            if ($baseValue >= 0 && $baseValue <= 20) {
                return $baseValue;
            }
        }

        if ($isDouble && 0 === $storedValue % 2) {
            $baseValue = intdiv($storedValue, 2);
            if (($baseValue >= 0 && $baseValue <= 20) || 25 === $baseValue) {
                return $baseValue;
            }
        }

        return $storedValue;
    }
}
