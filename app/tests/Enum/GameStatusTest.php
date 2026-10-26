<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\GameStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GameStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{GameStatus, bool, bool, bool}> status, canFinish, canAbort, canUndo
     */
    public static function statusProvider(): iterable
    {
        yield 'lobby' => [GameStatus::Lobby, false, false, false];
        yield 'started' => [GameStatus::Started, true, true, true];
        yield 'finished' => [GameStatus::Finished, true, false, true];
        yield 'aborted' => [GameStatus::Aborted, false, true, false];
    }

    #[DataProvider('statusProvider')]
    public function testActionsAllowedFromEachStatus(GameStatus $status, bool $canFinish, bool $canAbort, bool $canUndo): void
    {
        self::assertSame($canFinish, $status->canFinish());
        self::assertSame($canAbort, $status->canAbort());
        self::assertSame($canUndo, $status->canUndo());
    }
}
