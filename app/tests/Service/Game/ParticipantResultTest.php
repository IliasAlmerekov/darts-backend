<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Dto\ParticipantTotalsDto;
use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Service\Game\ParticipantResult;
use PHPUnit\Framework\TestCase;

final class ParticipantResultTest extends TestCase
{
    public function testFirstCheckoutAssignsWinnerAndClearsOtherWinnerFlags(): void
    {
        $game = new Game();
        $winner = (new GamePlayers())->setPlayer(new User())->setScore(0);
        $other = (new GamePlayers())->setScore(100)->setIsWinner(true);
        $third = (new GamePlayers())->setScore(50);
        $game->addGamePlayer($winner)->addGamePlayer($other)->addGamePlayer($third);

        self::assertFalse(ParticipantResult::recordCheckout($game, $winner));
        self::assertSame(1, $winner->getPlacement());
        self::assertSame($winner->getPlayer(), $game->getWinner());
        self::assertTrue($winner->isWinner());
        self::assertFalse($other->isWinner());
    }

    public function testLaterCheckoutCompletesGameAndRanksSeats(): void
    {
        $game = new Game();
        $first = (new GamePlayers())->setPlayer(new User())->setScore(0)->setPlacement(1)->setIsWinner(true);
        $second = (new GamePlayers())->setPlayer(new User())->setScore(0)->setPosition(2);
        $last = (new GamePlayers())->setScore(50)->setPosition(1);
        $game->addGamePlayer($last)->addGamePlayer($second)->addGamePlayer($first);
        $game->setWinner($first->getPlayer());

        self::assertTrue(ParticipantResult::recordCheckout($game, $second));
        self::assertSame(2, $second->getPlacement());
        self::assertFalse($second->isWinner());
        ParticipantResult::rankFinishedGame($game);
        self::assertSame([1, 2, 3], [$first->getPlacement(), $second->getPlacement(), $last->getPlacement()]);
        self::assertSame($first->getPlayer(), $game->getWinner());
    }

    public function testManualEndRanksCheckoutsThenScoresAndTurnOrder(): void
    {
        $game = new Game();
        $first = (new GamePlayers())->setPlayer(new User())->setScore(0)->setPlacement(1);
        $second = (new GamePlayers())->setScore(0)->setPlacement(2);
        $low = (new GamePlayers())->setScore(20)->setPosition(3);
        $tieFirst = (new GamePlayers())->setScore(40)->setPosition(1);
        $tieSecond = (new GamePlayers())->setScore(40)->setPosition(2);
        foreach ([$tieSecond, $second, $low, $tieFirst, $first] as $seat) {
            $game->addGamePlayer($seat);
        }

        ParticipantResult::rankFinishedGame($game);
        self::assertSame([1, 2, 3, 4, 5], [$first->getPlacement(), $second->getPlacement(), $low->getPlacement(), $tieFirst->getPlacement(), $tieSecond->getPlacement()]);
        self::assertSame($first->getPlayer(), $game->getWinner());
        self::assertTrue($first->isWinner());
        self::assertFalse($second->isWinner());
    }

    public function testManualEndWithoutCheckoutSelectsLowestScore(): void
    {
        $game = new Game();
        $winner = (new GamePlayers())->setPlayer(new User())->setScore(20)->setPosition(2);
        $other = (new GamePlayers())->setScore(50)->setPosition(1)->setIsWinner(true);
        $game->addGamePlayer($other)->addGamePlayer($winner);

        ParticipantResult::rankFinishedGame($game);
        self::assertSame([1, 2], [$winner->getPlacement(), $other->getPlacement()]);
        self::assertSame($winner->getPlayer(), $game->getWinner());
        self::assertTrue($winner->isWinner());
        self::assertFalse($other->isWinner());
    }
    public function testSeatsSortByPlacementWithUnplacedSeatsLastByRemainingScore(): void
    {
        // The positions are the turn order and contradict the placements.
        $third = (new GamePlayers())->setPosition(1)->setPlacement(3);
        $first = (new GamePlayers())->setPosition(3)->setPlacement(1);
        $unplacedHigh = (new GamePlayers())->setScore(120);
        $unplacedLow = (new GamePlayers())->setScore(40);
        $unplacedWithoutScore = new GamePlayers();
        $seats = [$unplacedWithoutScore, $third, $unplacedHigh, $first, $unplacedLow];

        usort($seats, ParticipantResult::compareByPlacement(...));

        self::assertSame([$first, $third, $unplacedLow, $unplacedHigh, $unplacedWithoutScore], $seats);
    }

    public function testDisplayNamePrefersTheSeatSnapshot(): void
    {
        $user = (new User())->setUsername('ilias')->setDisplayName('Ilias');
        $seat = (new GamePlayers())->setPlayer($user)->setDisplayNameSnapshot('Ilias (old)');

        self::assertSame('Ilias (old)', ParticipantResult::displayName($seat));
    }

    public function testDisplayNameFallsBackToTheUsersDisplayNameThenUsername(): void
    {
        $named = (new User())->setUsername('mia')->setDisplayName('Mia');
        $unnamed = (new User())->setUsername('tom');

        self::assertSame('Mia', ParticipantResult::displayName((new GamePlayers())->setPlayer($named)->setDisplayNameSnapshot('  ')));
        self::assertSame('tom', ParticipantResult::displayName((new GamePlayers())->setPlayer($unnamed)));
    }

    public function testDisplayNameIsNullWithoutAPlayer(): void
    {
        self::assertNull(ParticipantResult::displayName(new GamePlayers()));
    }

    public function testThreeDartAverageIsPointsPerDartTimesThree(): void
    {
        self::assertSame(60.6, ParticipantResult::threeDartAverage(new ParticipantTotalsDto(turns: 2, dartsThrown: 5, totalScore: 101.0)));
        self::assertSame(45.0, ParticipantResult::threeDartAverage(new ParticipantTotalsDto(turns: 1, dartsThrown: 2, totalScore: 30.0)));
    }

    public function testThreeDartAverageIsZeroWithoutDarts(): void
    {
        self::assertSame(0.0, ParticipantResult::threeDartAverage(new ParticipantTotalsDto(turns: 0, dartsThrown: 0, totalScore: 0.0)));
        self::assertSame(0.0, ParticipantResult::threeDartAverage(null));
    }
}
