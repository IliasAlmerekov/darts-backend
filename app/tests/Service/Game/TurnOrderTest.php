<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Entity\GamePlayers;
use App\Service\Game\TurnOrder;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class TurnOrderTest extends TestCase
{
    public function testSeatsSortByPositionWithUnplacedSeatsLast(): void
    {
        $unplaced = $this->seat(1, null);
        $second = $this->seat(2, 2);
        $first = $this->seat(3, 1);
        $seats = [$unplaced, $second, $first];

        usort($seats, TurnOrder::compare(...));

        self::assertSame([$first, $second, $unplaced], $seats);
    }

    public function testSeatIdBreaksAPositionTie(): void
    {
        $later = $this->seat(9, 1);
        $earlier = $this->seat(4, 1);
        $unplacedLater = $this->seat(8, null);
        $unplacedEarlier = $this->seat(5, null);
        $seats = [$unplacedLater, $later, $unplacedEarlier, $earlier];

        usort($seats, TurnOrder::compare(...));

        self::assertSame([$earlier, $later, $unplacedEarlier, $unplacedLater], $seats);
    }

    public function testRoundStateOfAPlayerWithoutThrowsIsEmpty(): void
    {
        self::assertSame(
            ['throwsCount' => 0, 'lastThrowNumber' => null, 'lastThrowValue' => null, 'lastThrowBust' => false],
            TurnOrder::roundState([], 7),
        );
    }

    public function testRoundStateReadsThePlayersEntry(): void
    {
        $snapshot = [
            7 => ['throwsCount' => 2, 'lastThrowNumber' => 2, 'lastThrowValue' => 60, 'lastThrowBust' => true],
            8 => ['throwsCount' => 1, 'lastThrowNumber' => 1, 'lastThrowValue' => 5, 'lastThrowBust' => false],
        ];

        self::assertSame(
            ['throwsCount' => 2, 'lastThrowNumber' => 2, 'lastThrowValue' => 60, 'lastThrowBust' => true],
            TurnOrder::roundState($snapshot, 7),
        );
    }

    public function testPlainSeatsSkipCompletedTurns(): void
    {
        $seats = [
            ['playerId' => 1, 'score' => 0],
            ['playerId' => 2, 'score' => 100],
            ['playerId' => 3, 'score' => 100],
            ['playerId' => 4, 'score' => 100],
        ];
        $snapshot = [
            2 => ['throwsCount' => 1, 'lastThrowNumber' => 1, 'lastThrowValue' => 20, 'lastThrowBust' => true],
            3 => ['throwsCount' => 3, 'lastThrowNumber' => 3, 'lastThrowValue' => 20, 'lastThrowBust' => false],
        ];
        self::assertSame(4, TurnOrder::activePlayerIdFromSeats($seats, $snapshot));
    }

    public function testRecordThrowReturnsUpdatedSnapshot(): void
    {
        $original = [];
        $first = TurnOrder::recordThrow($original, 7, 1, 20, false);
        self::assertSame([], $original);
        self::assertSame(['throwsCount' => 1, 'lastThrowNumber' => 1, 'lastThrowValue' => 20, 'lastThrowBust' => false], $first[7]);
        $second = TurnOrder::recordThrow($first, 7, 2, 40, true);
        self::assertSame(['throwsCount' => 2, 'lastThrowNumber' => 2, 'lastThrowValue' => 40, 'lastThrowBust' => true], $second[7]);
    }

    public function testEmptyGameHasNoCompleteRound(): void
    {
        self::assertFalse(TurnOrder::isRoundComplete(new \App\Entity\Game(), []));
    }

    public function testGameAndPlainSeatsResolveTheSamePlayerAndRoundCompletion(): void
    {
        $game = (new \App\Entity\Game())->setStartScore(301);
        $late = $this->seat(2, null);
        $first = $this->seat(1, 1);
        $user1 = new \App\Entity\User();
        $user2 = new \App\Entity\User();
        (new ReflectionProperty($user1, 'id'))->setValue($user1, 1);
        (new ReflectionProperty($user2, 'id'))->setValue($user2, 2);
        $first->setPlayer($user1);
        $late->setPlayer($user2)->setScore(100);
        $game->addGamePlayer($late)->addGamePlayer($first);
        $seats = [['playerId' => 1, 'score' => 301], ['playerId' => 2, 'score' => 100]];
        self::assertSame(1, TurnOrder::activePlayerId($game, []));
        self::assertSame(TurnOrder::activePlayerIdFromSeats($seats, []), TurnOrder::activePlayerId($game, []));
        self::assertFalse(TurnOrder::isRoundComplete($game, []));
        $snapshot = [
            1 => ['throwsCount' => 3, 'lastThrowNumber' => 3, 'lastThrowValue' => 20, 'lastThrowBust' => false],
            2 => ['throwsCount' => 1, 'lastThrowNumber' => 1, 'lastThrowValue' => 20, 'lastThrowBust' => true],
        ];
        self::assertNull(TurnOrder::activePlayerId($game, $snapshot));
        self::assertTrue(TurnOrder::isRoundComplete($game, $snapshot));
    }

    private function seat(int $seatId, ?int $position): GamePlayers
    {
        $seat = new GamePlayers();
        (new ReflectionProperty(GamePlayers::class, 'gamePlayerId'))->setValue($seat, $seatId);
        (new ReflectionProperty(GamePlayers::class, 'position'))->setValue($seat, $position);

        return $seat;
    }
}
