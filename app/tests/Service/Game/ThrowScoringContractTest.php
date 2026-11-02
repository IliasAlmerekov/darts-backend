<?php

/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Enum\OutRule;
use App\Service\Game\DartThrow;
use App\Service\Game\ThrowScoring;
use PHPUnit\Framework\TestCase;

final class ThrowScoringContractTest extends TestCase
{
    public function testCasesMatchScoring(): void
    {
        foreach ($this->cases() as $index => $case) {
            $dart = $case['dart'];
            $result = ThrowScoring::score($case['scoreBefore'], 0, $dart['value'], $dart['isDouble'], $dart['isTriple'], OutRule::from($case['outRule']));
            self::assertSame($case['bust'], $result->bust, "Case $index: bust");
            self::assertSame($case['scoreAfter'], $result->score, "Case $index: score after");
        }
    }

    public function testCasesCoverEveryBoardSegmentAndRemainder(): void
    {
        $cases = $this->cases();
        foreach (OutRule::cases() as $rule) {
            $segments = [];
            $remainders = [];
            $miss = false;
            $normal = false;
            foreach ($cases as $case) {
                if ($case['outRule'] !== $rule->value) {
                    continue;
                }
                $dart = $case['dart'];
                self::assertGreaterThan(0, $case['scoreBefore']);
                self::assertFalse($dart['isDouble'] && $dart['isTriple']);
                self::assertTrue(DartThrow::isBoardSegment($dart['value'], $dart['isDouble'], $dart['isTriple']));
                $value = $dart['value'] * ($dart['isTriple'] ? 3 : ($dart['isDouble'] ? 2 : 1));
                $remaining = $case['scoreBefore'] - $value;
                if (0 === $remaining) {
                    self::assertNotSame(0, $dart['value']);
                    $segments[] = [$dart['value'], $dart['isDouble'], $dart['isTriple']];
                }
                $remainders[] = $remaining;
                $miss = $miss || (0 === $dart['value'] && !$dart['isDouble'] && !$dart['isTriple']);
                $normal = $normal || ($dart['value'] > 0 && $remaining > 2 && !$case['bust']);
            }
            foreach ([[false, false], [true, false], [false, true]] as [$double, $triple]) {
                for ($value = 1; $value <= 25; ++$value) {
                    if (DartThrow::isBoardSegment($value, $double, $triple)) {
                        self::assertContains([$value, $double, $triple], $segments, "{$rule->value}: missing segment $value double=$double triple=$triple");
                    }
                }
            }
            self::assertContains(1, $remainders, $rule->value);
            if (OutRule::Triple === $rule) {
                self::assertContains(2, $remainders, $rule->value);
            }
            self::assertNotEmpty(array_filter($remainders, static fn (int $remaining): bool => $remaining < 0), $rule->value);
            self::assertTrue($normal, $rule->value . ': normal dart');
            self::assertTrue($miss, $rule->value . ': miss');
        }
    }

    /**
     * @return list<array{outRule: string, scoreBefore: int, dart: array{value: int, isDouble: bool, isTriple: bool}, bust: bool, scoreAfter: int}>
     */
    private function cases(): array
    {
        $json = file_get_contents(__DIR__ . '/../../../../docs/contract/throw-scoring-cases.json');
        self::assertNotFalse($json);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
