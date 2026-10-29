<?php

/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\OutRule;
use App\Service\Game\ThrowScoring;
use PHPUnit\Framework\TestCase;

final class OutRuleTest extends TestCase
{
    public function testDeadScores(): void
    {
        foreach ([-1, 0, 1, 2, 3] as $score) {
            self::assertFalse(OutRule::Single->isDeadScore($score));
            self::assertSame(1 === $score, OutRule::Double->isDeadScore($score));
            self::assertSame(1 === $score || 2 === $score, OutRule::Triple->isDeadScore($score));
        }
    }
    public function testCheckoutDarts(): void
    {
        foreach ([[false, false], [true, false], [false, true], [true, true]] as [$double, $triple]) {
            self::assertTrue(OutRule::Single->allowsCheckoutWith($double, $triple));
            self::assertSame($double, OutRule::Double->allowsCheckoutWith($double, $triple));
            self::assertSame($triple, OutRule::Triple->allowsCheckoutWith($double, $triple));
        }
    }

    public function testCheckableEdgeScores(): void
    {
        $scores = [-1, 0, 1, 2, 3, 20, 25, 40, 50, 51, 60, 61];
        $expected = [
            [false, false, true, true, true, true, true, true, true, true, true, false],
            [false, false, false, true, false, true, false, true, true, false, false, false],
            [false, false, false, false, true, false, false, false, false, true, true, false],
        ];
        foreach (OutRule::cases() as $index => $rule) {
            foreach ($scores as $i => $score) {
                self::assertSame($expected[$index][$i], $rule->canCheckOutInOneDart($score), $rule->value.' '.$score);
            }
        }
    }

    public function testCheckableScoresMatchLegalDarts(): void
    {
        foreach (OutRule::cases() as $rule) {
            for ($score = 1; $score <= 70; $score++) {
                $checkable = false;
                foreach (range(0, 20) as $base) {
                    foreach ([[false, false], [true, false], [false, true]] as [$double, $triple]) {
                        if (0 === $base && ($double || $triple)) {
                            continue;
                        }
                        $result = ThrowScoring::score($score, 0, $base, $double, $triple, $rule);
                        $checkable = $checkable || (!$result->bust && 0 === $result->score);
                    }
                }
                foreach ([[false, false], [true, false]] as [$double, $triple]) {
                    $result = ThrowScoring::score($score, 0, 25, $double, $triple, $rule);
                    $checkable = $checkable || (!$result->bust && 0 === $result->score);
                }
                self::assertSame($checkable, $rule->canCheckOutInOneDart($score), $rule->value.' '.$score);
            }
        }
    }
}
