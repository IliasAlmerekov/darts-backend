<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Service\Game\DartThrow;
use PHPUnit\Framework\TestCase;

final class DartThrowTest extends TestCase
{
    public function testTripleReturnsTheSegmentValue(): void
    {
        self::assertSame(20, DartThrow::baseValue(60, false, true));
        self::assertSame(0, DartThrow::baseValue(0, false, true));
    }

    public function testDoubleReturnsTheSegmentValue(): void
    {
        self::assertSame(25, DartThrow::baseValue(50, true, false));
        self::assertSame(16, DartThrow::baseValue(32, true, false));
    }

    public function testSingleKeepsTheStoredValue(): void
    {
        self::assertSame(25, DartThrow::baseValue(25, false, false));
        self::assertSame(60, DartThrow::baseValue(60, false, false));
    }

    public function testValueThatIsNoMultipleOfASegmentStaysUnchanged(): void
    {
        self::assertSame(61, DartThrow::baseValue(61, false, true));
        self::assertSame(63, DartThrow::baseValue(63, false, true));
        self::assertSame(45, DartThrow::baseValue(45, true, false));
        self::assertSame(44, DartThrow::baseValue(44, true, false));
    }

    public function testStoredTripleAndDoubleValuesDecodeAsBefore(): void
    {
        $expected = [
            // stored value => [as triple, as double]
            0 => [0, 0],
            3 => [1, 3],
            50 => [50, 25],
            60 => [20, 60],
            61 => [61, 61],
            63 => [63, 63],
            44 => [44, 44],
            45 => [15, 45],
        ];
        foreach ($expected as $storedValue => [$asTriple, $asDouble]) {
            self::assertSame($asTriple, DartThrow::baseValue($storedValue, false, true), "triple {$storedValue}");
            self::assertSame($asDouble, DartThrow::baseValue($storedValue, true, false), "double {$storedValue}");
        }
    }

    public function testBoardSegmentEdgesPerDartKind(): void
    {
        $cases = [
            // [base value, is double, is triple, legal]
            [0, false, true, false],
            [1, false, true, true],
            [20, false, true, true],
            [21, false, true, false],
            [25, false, true, false],
            [0, true, false, false],
            [1, true, false, true],
            [20, true, false, true],
            [21, true, false, false],
            [25, true, false, true],
            [-1, false, false, false],
            [0, false, false, true],
            [1, false, false, true],
            [20, false, false, true],
            [21, false, false, false],
            [25, false, false, true],
        ];
        foreach ($cases as [$baseValue, $isDouble, $isTriple, $legal]) {
            self::assertSame(
                $legal,
                DartThrow::isBoardSegment($baseValue, $isDouble, $isTriple),
                sprintf('base %d, double %s, triple %s', $baseValue, var_export($isDouble, true), var_export($isTriple, true)),
            );
        }
    }
}
