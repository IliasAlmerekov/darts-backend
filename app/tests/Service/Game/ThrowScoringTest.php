<?php

/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Enum\OutRule;
use App\Entity\Game;
use App\Exception\Game\InvalidThrowException;
use App\Service\Game\ThrowScoring;
use PHPUnit\Framework\TestCase;

final class ThrowScoringTest extends TestCase
{
    public function testScoringRules(): void
    {
        $cases = [
            [OutRule::Single, 20, 20, false, false, 0, false],
            [OutRule::Single, 21, 20, false, false, 1, false],
            [OutRule::Single, 22, 20, false, false, 2, false],
            [OutRule::Single, 19, 20, false, false, 19, true],
            [OutRule::Double, 20, 10, true, false, 0, false],
            [OutRule::Double, 20, 20, false, false, 20, true],
            [OutRule::Double, 21, 20, false, false, 21, true],
            [OutRule::Double, 22, 20, false, false, 2, false],
            [OutRule::Double, 19, 20, false, false, 19, true],
            [OutRule::Triple, 18, 6, false, true, 0, false],
            [OutRule::Triple, 20, 20, false, false, 20, true],
            [OutRule::Triple, 21, 20, false, false, 21, true],
            [OutRule::Triple, 22, 20, false, false, 22, true],
            [OutRule::Triple, 19, 20, false, false, 19, true],
        ];
        foreach ($cases as [$rule, $score, $value, $double, $triple, $expected, $bust]) {
            $result = ThrowScoring::score($score, 0, $value, $double, $triple, $rule);
            self::assertSame($expected, $result->score);
            self::assertSame($bust, $result->bust);
        }
        $result = ThrowScoring::score(21, 20, 20, false, false, OutRule::Double);
        self::assertSame(41, $result->score);
        self::assertSame(20, $result->value);
        self::assertTrue($result->bust);
    }
    public function testOutRuleMapping(): void
    {
        $game = new Game();
        foreach (OutRule::cases() as $rule) {
            $game->setOutRule($rule);
            self::assertSame($rule, $game->outRule());
            self::assertSame(OutRule::Double === $rule, $game->isDoubleOut());
            self::assertSame(OutRule::Triple === $rule, $game->isTripleOut());
        }
    }

    public function testInvalidInputsKeepTheirMessages(): void
    {
        $cases = [
            [1, true, true, 'Throw cannot be both double and triple at the same time.'],
            [0, false, true, 'Triple throws require a base value between 1 and 20.'],
            [21, false, true, 'Triple throws require a base value between 1 and 20.'],
            [0, true, false, 'Double throws require a base value between 1 and 20, or 25 for bull.'],
            [21, true, false, 'Double throws require a base value between 1 and 20, or 25 for bull.'],
            [-1, false, false, 'Single throws require a base value between 0 and 20, or 25 for bull.'],
            [21, false, false, 'Single throws require a base value between 0 and 20, or 25 for bull.'],
        ];
        foreach ($cases as [$value, $double, $triple, $message]) {
            try {
                ThrowScoring::score(100, 0, $value, $double, $triple, OutRule::Single);
                self::fail('Expected InvalidThrowException');
            } catch (InvalidThrowException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }
}
