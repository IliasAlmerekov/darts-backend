<?php

/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Enum\OutRule;
use App\Exception\Game\InvalidThrowException;

final class ThrowScoring
{
    public static function score(int $score, int $earlierPoints, int $baseValue, bool $isDouble, bool $isTriple, OutRule $rule): ThrowScore
    {
        if ($isDouble && $isTriple) {
            throw new InvalidThrowException('Throw cannot be both double and triple at the same time.');
        }
        if ($isTriple && ($baseValue < 1 || $baseValue > 20)) {
            throw new InvalidThrowException('Triple throws require a base value between 1 and 20.');
        }
        if ($isDouble && ($baseValue < 1 || $baseValue > 20) && 25 !== $baseValue) {
            throw new InvalidThrowException('Double throws require a base value between 1 and 20, or 25 for bull.');
        }
        if (!$isDouble && !$isTriple && ($baseValue < 0 || $baseValue > 20) && 25 !== $baseValue) {
            throw new InvalidThrowException('Single throws require a base value between 0 and 20, or 25 for bull.');
        }
        $value = $baseValue * ($isTriple ? 3 : ($isDouble ? 2 : 1));
        $remaining = $score - $value;
        $bust = $remaining < 0
            || (OutRule::Single !== $rule && 1 === $remaining)
            || (OutRule::Triple === $rule && 2 === $remaining)
            || (0 === $remaining && OutRule::Double === $rule && !$isDouble)
            || (0 === $remaining && OutRule::Triple === $rule && !$isTriple);

        return new ThrowScore($value, $bust ? $score + $earlierPoints : $remaining, $bust);
    }
}
