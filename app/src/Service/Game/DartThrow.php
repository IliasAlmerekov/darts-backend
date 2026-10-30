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
            if (self::isBoardSegment($baseValue, false, true)) {
                return $baseValue;
            }
        }

        if ($isDouble && 0 === $storedValue % 2) {
            $baseValue = intdiv($storedValue, 2);
            if (self::isBoardSegment($baseValue, true, false)) {
                return $baseValue;
            }
        }

        return $storedValue;
    }

    /**
     * Whether a dart of this kind can hit this base value: a triple 1 to 20,
     * a double 1 to 20 or 25 (bull), a single 0 (miss) to 20 or 25.
     *
     * @param int  $baseValue
     * @param bool $isDouble
     * @param bool $isTriple
     *
     * @return bool
     */
    public static function isBoardSegment(int $baseValue, bool $isDouble, bool $isTriple): bool
    {
        if ($isTriple) {
            return $baseValue >= 1 && $baseValue <= 20;
        }
        $lowest = $isDouble ? 1 : 0;

        return ($baseValue >= $lowest && $baseValue <= 20) || 25 === $baseValue;
    }
}
