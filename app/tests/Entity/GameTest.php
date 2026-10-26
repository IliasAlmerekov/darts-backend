<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class GameTest extends TestCase
{
    public function testSeatsWithScoreLeftLoseTheirPlacementAndTheOthersKeepIt(): void
    {
        $game = (new Game())->setStartScore(301);
        $checkedOut = $this->seat($game, 0, 1, true);
        $unfinished = $this->seat($game, 80, 2, false);
        $unplayed = $this->seat($game, null, 3, false);

        $game->takeBackPlacementsFromSeatsWithScoreLeft();

        self::assertSame(1, $checkedOut->getPlacement());
        self::assertTrue($checkedOut->isWinner());
        self::assertNull($unfinished->getPlacement());
        self::assertNull($unplayed->getPlacement());
    }

    public function testTheWinnerKeepsTheWinWhileTheirScoreIsZero(): void
    {
        $game = (new Game())->setStartScore(301);
        $winner = (new User())->setUsername('winner');
        $game->setWinner($winner);
        $this->seat($game, 0, 1, true);

        $game->takeBackPlacementsFromSeatsWithScoreLeft();

        self::assertSame($winner, $game->getWinner());
    }

    public function testTheWinnerLosesTheWinWhenTheirScoreIsAboveZero(): void
    {
        $game = (new Game())->setStartScore(301);
        $game->setWinner((new User())->setUsername('winner'));
        $winnerSeat = $this->seat($game, 20, 1, true);

        $game->takeBackPlacementsFromSeatsWithScoreLeft();

        self::assertNull($game->getWinner());
        self::assertFalse($winnerSeat->isWinner());
        self::assertNull($winnerSeat->getPlacement());
    }

    private function seat(Game $game, ?int $score, int $placement, bool $isWinner): GamePlayers
    {
        $seat = (new GamePlayers())->setPlacement($placement)->setIsWinner($isWinner);
        if (null !== $score) {
            $seat->setScore($score);
        }
        $game->addGamePlayer($seat);

        return $seat;
    }
}
