<?php

/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\Round;
use App\Entity\RoundThrows;
use App\Entity\User;
use App\Repository\RoundRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Game\UndoThrowRules;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class UndoThrowRulesTest extends TestCase
{
    public function testRestoreScoreUsesPreviousThrowOrStartScore(): void
    {
        $game = (new Game())->setStartScore(301);
        (new ReflectionProperty($game, 'gameId'))->setValue($game, 10);
        $user = new User();
        (new ReflectionProperty($user, 'id'))->setValue($user, 2);
        $seat = (new GamePlayers())->setPlayer($user)->setScore(0);
        $game->addGamePlayer($seat);
        $throw = (new RoundThrows())->setPlayer($user)->setThrowId(5);
        $previous = (new RoundThrows())->setScore(40);
        $throws = $this->createMock(RoundThrowsRepositoryInterface::class);
        $throws->expects(self::exactly(2))->method('findLatestForGameAndPlayerBeforeThrow')
            ->with(10, 2, 5)->willReturnOnConsecutiveCalls($previous, null);

        self::assertSame($seat, UndoThrowRules::findSeat($game, $throw));
        UndoThrowRules::restoreScore($game, $throw, $seat, $throws);
        self::assertSame(40, $seat->getScore());
        UndoThrowRules::restoreScore($game, $throw, $seat, $throws);
        self::assertSame(301, $seat->getScore());
    }

    public function testReopenRoundRemovesOnlyEmptyNextRound(): void
    {
        $game = (new Game())->setRound(2);
        $round = (new Round())->setGame($game)->setRoundNumber(1)->setFinishedAt(new DateTimeImmutable());
        $next = (new Round())->setGame($game)->setRoundNumber(2);
        $game->addRound($round);
        $game->addRound($next);
        $throw = (new RoundThrows())->setRound($round);
        $rounds = $this->createMock(RoundRepositoryInterface::class);
        $rounds->expects(self::once())->method('findOneBy')->with(['game' => $game, 'roundNumber' => 2])->willReturn($next);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('remove')->with($next);

        UndoThrowRules::reopenRound($game, $throw, $rounds, $em);

        self::assertSame(1, $game->getRound());
        self::assertNull($round->getFinishedAt());
        self::assertFalse($game->getRounds()->contains($next));
    }
}
