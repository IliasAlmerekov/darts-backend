<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Entity\Game;
use App\Entity\Round;
use App\Entity\RoundThrows;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Game\GameStateReader;
use App\Service\Game\GameStateVersionServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[AllowMockObjectsWithoutExpectations]
final class GameStateReaderAckTest extends TestCase
{
    public function testThrowAckUsesSeatNameAndKeepsBustDartValue(): void
    {
        $game = (new Game())->setGameId(44)->setStatus(GameStatus::Started)->setRound(2)->setStartScore(301);
        $player = (new User())->setUsername('alex')->setDisplayName('Original');
        (new ReflectionProperty(User::class, 'id'))->setValue($player, 10);
        $throw = (new RoundThrows())->setPlayer($player)->setRound((new Round())->setRoundNumber(2))
            ->setValue(25)->setIsBust(true)->setScore(26);
        (new ReflectionProperty(RoundThrows::class, 'throwId'))->setValue($throw, 501);
        $players = $this->createMock(GamePlayersRepositoryInterface::class);
        $players->method('findGameStatePlayersByGameId')->willReturn([[
            'playerId' => 10, 'name' => 'Seat Name', 'position' => 1, 'placement' => null,
            'score' => 26, 'isGuest' => true,
        ]]);
        $throws = $this->createMock(RoundThrowsRepositoryInterface::class);
        $throws->expects(self::never())->method('findRoundHistoryForGame');
        $throws->expects(self::once())->method('findRoundAndLatestThrowsForGame')->with(44, 2)->willReturn([[
            'playerId' => 10, 'roundNumber' => 2, 'throwNumber' => 1, 'value' => 25,
            'isDouble' => false, 'isTriple' => false, 'isBust' => true,
        ]]);
        $versions = $this->createMock(GameStateVersionServiceInterface::class);
        $versions->expects(self::once())->method('buildStateVersion')->with($game, 501)->willReturn('state-v2');
        $ack = (new GameStateReader($players, $throws, $versions))->buildThrowAck($game, $throw);
        self::assertNotNull($ack->throw);
        self::assertSame('Seat Name', $ack->throw->playerName);
        self::assertSame(25, $ack->throw->value);
        self::assertTrue($ack->throw->isBust);
        self::assertTrue($ack->scoreboardDelta->changedPlayers[0]->isGuest);
        self::assertTrue($ack->scoreboardDelta->changedPlayers[0]->isBust);
    }

    public function testUndoAckReadsOnlyTheCurrentRoundAndTheLatestThrows(): void
    {
        $game = (new Game())->setGameId(45)->setStatus(GameStatus::Started)->setRound(7)->setStartScore(301);
        $players = $this->createMock(GamePlayersRepositoryInterface::class);
        $players->method('findGameStatePlayersByGameId')->willReturn([
            ['playerId' => 10, 'name' => 'Anna', 'position' => 1, 'placement' => null, 'score' => 120, 'isGuest' => false],
            ['playerId' => 11, 'name' => 'Boris', 'position' => 2, 'placement' => null, 'score' => 90, 'isGuest' => false],
        ]);
        $throws = $this->createMock(RoundThrowsRepositoryInterface::class);
        $throws->expects(self::never())->method('findRoundHistoryForGame');
        // Boris busted in round 6 and has not thrown in round 7; Anna is on her second dart.
        $throws->expects(self::once())->method('findRoundAndLatestThrowsForGame')->with(45, 7)->willReturn([
            ['playerId' => 11, 'roundNumber' => 6, 'throwNumber' => 2, 'value' => 60, 'isDouble' => false, 'isTriple' => true, 'isBust' => true],
            ['playerId' => 10, 'roundNumber' => 7, 'throwNumber' => 1, 'value' => 20, 'isDouble' => false, 'isTriple' => false, 'isBust' => false],
        ]);
        $versions = $this->createMock(GameStateVersionServiceInterface::class);
        $versions->method('buildStateVersion')->willReturn('state-v7');

        $ack = (new GameStateReader($players, $throws, $versions))->buildUndoAck($game, null);

        self::assertSame('state-v7', $ack->stateVersion);
        self::assertNull($ack->undoneThrow);
        $scoreboard = $ack->scoreboardDelta->changedPlayers;
        self::assertSame([[10, true, false], [11, false, true]], [
            [$scoreboard[0]->playerId, $scoreboard[0]->isActive, $scoreboard[0]->isBust],
            [$scoreboard[1]->playerId, $scoreboard[1]->isActive, $scoreboard[1]->isBust],
        ]);
    }
}
