<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Entity\Game;
use App\Enum\GameStatus;
use App\Exception\Game\GameAbortNotAllowedException;
use App\Service\Game\GameAbortService;
use App\Tests\Support\LockedGameTransactionFake;
use PHPUnit\Framework\TestCase;

final class GameAbortServiceTest extends TestCase
{
    public function testAbortMarksTheLockedGameAbortedInATransactionWithoutRetry(): void
    {
        $game = (new Game())->setGameId(7)->setStatus(GameStatus::Started);
        $lockedGame = new LockedGameTransactionFake($game);

        (new GameAbortService($lockedGame))->abortGame($game);

        self::assertSame(GameStatus::Aborted, $game->getStatus());
        self::assertSame(['run'], $lockedGame->calls);
    }

    public function testAbortRejectsAFinishedGame(): void
    {
        $game = (new Game())->setGameId(7)->setStatus(GameStatus::Finished);

        $this->expectException(GameAbortNotAllowedException::class);
        (new GameAbortService(new LockedGameTransactionFake($game)))->abortGame($game);
    }
}
