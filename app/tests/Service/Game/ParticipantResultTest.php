<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Entity\GamePlayers;
use App\Entity\User;
use App\Service\Game\ParticipantResult;
use PHPUnit\Framework\TestCase;

final class ParticipantResultTest extends TestCase
{
    public function testSeatsSortByPlacementWithUnplacedSeatsLastByRemainingScore(): void
    {
        $third = (new GamePlayers())->setPosition(3);
        $first = (new GamePlayers())->setPosition(1);
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

    public function testMaxRoundNumberIsTheLaterOfFinishedRoundsAndTheLastRoundThrown(): void
    {
        self::assertSame(5, ParticipantResult::maxRoundNumber(3, [7 => 4, 8 => 5]));
        self::assertSame(6, ParticipantResult::maxRoundNumber(6, [7 => 4]));
        self::assertSame(3, ParticipantResult::maxRoundNumber(3, []));
        self::assertNull(ParticipantResult::maxRoundNumber(null, []));
    }

    public function testCheckedOutParticipantPlayedOnlyTheirOwnRounds(): void
    {
        self::assertSame(4, ParticipantResult::roundsPlayed(0, 4, 6));
        self::assertSame(0, ParticipantResult::roundsPlayed(0, null, 6));
    }

    public function testParticipantStillInPlayedEveryRoundOfTheGame(): void
    {
        self::assertSame(6, ParticipantResult::roundsPlayed(40, 4, 6));
        self::assertSame(6, ParticipantResult::roundsPlayed(null, null, 6));
    }

    public function testRoundAverageDividesTheTotalByTheRoundsPlayed(): void
    {
        self::assertSame(45.0, ParticipantResult::roundAverage(90.0, 2));
    }

    public function testRoundAverageIsZeroWithoutRounds(): void
    {
        self::assertSame(0.0, ParticipantResult::roundAverage(90.0, 0));
        self::assertSame(0.0, ParticipantResult::roundAverage(90.0, null));
    }
}
