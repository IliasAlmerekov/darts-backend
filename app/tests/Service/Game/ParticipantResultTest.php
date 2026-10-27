<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Dto\ParticipantTotalsDto;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Service\Game\ParticipantResult;
use PHPUnit\Framework\TestCase;

final class ParticipantResultTest extends TestCase
{
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
