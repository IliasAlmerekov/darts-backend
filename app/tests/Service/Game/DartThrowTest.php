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
}
