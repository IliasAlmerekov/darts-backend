<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\SqlResultValueAccessor;
use PHPUnit\Framework\TestCase;

final class SqlResultValueAccessorTest extends TestCase
{
    public function testFindResolvesLowercaseAndSnakeCaseAliases(): void
    {
        $row = [
            'playerid' => '11',
            'games_played' => '7',
            'winnerrounds' => '3',
            'winner_name' => 'Alice',
        ];

        self::assertSame('11', SqlResultValueAccessor::find($row, ['playerId']));
        self::assertSame('7', SqlResultValueAccessor::find($row, ['gamesPlayed']));
        self::assertSame('3', SqlResultValueAccessor::find($row, ['winnerRounds']));
        self::assertSame('Alice', SqlResultValueAccessor::find($row, ['winnerName']));
    }

    public function testFindReturnsNullWhenKeyNotFound(): void
    {
        self::assertNull(SqlResultValueAccessor::find(['foo' => 'bar'], ['missingKey']));
    }

    public function testHasSupportsNumericAndAliasedKeys(): void
    {
        $row = [
            5 => '22.5',
            'score_average' => '21.0',
        ];

        self::assertTrue(SqlResultValueAccessor::has($row, ['scoreAverage']));
        self::assertTrue(SqlResultValueAccessor::has($row, [5]));
        self::assertFalse(SqlResultValueAccessor::has($row, ['missingKey']));
    }
}
