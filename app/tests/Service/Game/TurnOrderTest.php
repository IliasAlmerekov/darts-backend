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

    private function seat(int $seatId, ?int $position): GamePlayers
    {
        $seat = new GamePlayers();
        (new ReflectionProperty(GamePlayers::class, 'gamePlayerId'))->setValue($seat, $seatId);
        (new ReflectionProperty(GamePlayers::class, 'position'))->setValue($seat, $position);

        return $seat;
    }
}
