<?php

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Dto\ThrowDeltaDto;
use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Game\GameDeltaService;
use App\Service\Game\GameServiceInterface;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionProperty;

final class GameDeltaServiceTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    public function testBuildThrowAckKeepsThrowValueForBust(): void
    {
        $game = (new Game())
            ->setGameId(44)
            ->setStatus(GameStatus::Started)
            ->setRound(2)
            ->setStartScore(301);

        $player = (new User())
            ->setUsername('Alex')
            ->setDisplayName('Alex');
        $this->setPrivateProperty($player, 'id', 10);

        $gamePlayer = (new GamePlayers())
            ->setPlayer($player)
            ->setPosition(1)
            ->setScore(26);
        $game->addGamePlayer($gamePlayer);

        $roundThrowsRepository = $this->createStub(RoundThrowsRepositoryInterface::class);
        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->expects(self::once())
            ->method('findGameStatePlayersByGameId')
            ->with(44)
            ->willReturn([
                [
                    'playerId' => 10,
                    'name' => 'Alex',
                    'position' => 1,
                    'score' => 26,
                    'isGuest' => false,
                ],
            ]);

        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->expects(self::once())
            ->method('buildStateVersion')
            ->with($game, 501)
            ->willReturn('state-v2');
        $gameService->expects(self::once())
            ->method('calculateActivePlayer')
            ->with($game)
            ->willReturn(10);

        $service = new GameDeltaService($roundThrowsRepository, $gameService, $gamePlayersRepository);
        $ack = $service->buildThrowAck($game, [
            'id' => 501,
            'playerId' => 10,
            'playerName' => 'Alex',
            'value' => 25,
            'isDouble' => false,
            'isTriple' => false,
            'isBust' => true,
            'score' => 26,
            'roundNumber' => 2,
            'timestamp' => '2026-02-13T09:00:00+00:00',
        ]);

        self::assertNotNull($ack->throw);
        self::assertTrue($ack->throw->isBust);
        self::assertSame(25, $ack->throw->value);
        self::assertSame(26, $ack->throw->score);
        self::assertCount(1, $ack->scoreboardDelta->changedPlayers);
        self::assertTrue($ack->scoreboardDelta->changedPlayers[0]->isBust ?? false);
    }

    /**
     * @throws ReflectionException
     */
    public function testBuildThrowAckReusesProvidedRoundSnapshot(): void
    {
        $game = (new Game())
            ->setGameId(66)
            ->setStatus(GameStatus::Started)
            ->setRound(3)
            ->setStartScore(301);

        $activePlayer = (new User())
            ->setUsername('Alex')
            ->setDisplayName('Alex');
        $this->setPrivateProperty($activePlayer, 'id', 10);

        $otherPlayer = (new User())
            ->setUsername('Sam')
            ->setDisplayName('Sam');
        $this->setPrivateProperty($otherPlayer, 'id', 11);

        $game->addGamePlayer(
            (new GamePlayers())
                ->setPlayer($activePlayer)
                ->setPosition(1)
                ->setScore(121)
        );
        $game->addGamePlayer(
            (new GamePlayers())
                ->setPlayer($otherPlayer)
                ->setPosition(2)
                ->setScore(140)
        );

        $roundStateSnapshot = [
            10 => [
                'throwsCount' => 1,
                'lastThrowNumber' => 1,
                'lastThrowValue' => 60,
                'lastThrowBust' => true,
            ],
            11 => [
                'throwsCount' => 0,
                'lastThrowNumber' => null,
                'lastThrowValue' => null,
                'lastThrowBust' => false,
            ],
        ];

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->expects(self::never())
            ->method('findCurrentRoundThrowsForGamePlayers');

        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->expects(self::once())
            ->method('buildStateVersion')
            ->with($game, 701)
            ->willReturn('state-v4');
        $gameService->expects(self::once())
            ->method('calculateActivePlayer')
            ->with($game, $roundStateSnapshot)
            ->willReturn(11);

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->expects(self::once())
            ->method('findGameStatePlayersByGameId')
            ->with(66)
            ->willReturn([
                [
                    'playerId' => 10,
                    'name' => 'Alex',
                    'position' => 1,
                    'score' => 121,
                    'isGuest' => false,
                ],
                [
                    'playerId' => 11,
                    'name' => 'Sam',
                    'position' => 2,
                    'score' => 140,
                    'isGuest' => true,
                ],
            ]);

        $service = new GameDeltaService($roundThrowsRepository, $gameService, $gamePlayersRepository);
        $ack = $service->buildThrowAck(
            $game,
            [
                'id' => 701,
                'playerId' => 10,
                'playerName' => 'Alex',
                'value' => 60,
                'isDouble' => false,
                'isTriple' => true,
                'isBust' => true,
                'score' => 121,
                'roundNumber' => 3,
                'timestamp' => '2026-03-10T10:00:00+00:00',
            ],
            $roundStateSnapshot,
        );

        self::assertNotNull($ack->throw);
        self::assertSame(20, $ack->throw->value);
        self::assertSame(11, $ack->scoreboardDelta->changedPlayers[1]->playerId);
        self::assertTrue($ack->scoreboardDelta->changedPlayers[0]->isBust ?? false);
        self::assertTrue($ack->scoreboardDelta->changedPlayers[1]->isActive);
    }

    /**
     * @throws ReflectionException
     */
    public function testBuildUndoAckUsesCompactUndoPayload(): void
    {
        $game = (new Game())
            ->setGameId(55)
            ->setStatus(GameStatus::Started)
            ->setRound(2)
            ->setStartScore(301);

        $player = (new User())
            ->setUsername('Alex')
            ->setDisplayName('Alex');
        $this->setPrivateProperty($player, 'id', 10);

        $gamePlayer = (new GamePlayers())
            ->setPlayer($player)
            ->setPosition(1)
            ->setScore(51);
        $game->addGamePlayer($gamePlayer);

        $roundThrowsRepository = $this->createStub(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findCurrentRoundThrowsForGamePlayers')
            ->with(55, 2)
            ->willReturn([]);
        $roundThrowsRepository->method('findLatestForGame')
            ->with(55)
            ->willReturn(null);

        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->expects(self::once())
            ->method('buildStateVersion')
            ->with($game)
            ->willReturn('state-v3');
        $gameService->expects(self::once())
            ->method('calculateActivePlayer')
            ->with($game)
            ->willReturn(10);

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->expects(self::once())
            ->method('findGameStatePlayersByGameId')
            ->with(55)
            ->willReturn([
                [
                    'playerId' => 10,
                    'name' => 'Alex',
                    'position' => 1,
                    'score' => 51,
                    'isGuest' => false,
                ],
            ]);

        $service = new GameDeltaService($roundThrowsRepository, $gameService, $gamePlayersRepository);
        $undoneThrow = new ThrowDeltaDto(
            id: 901,
            playerId: 10,
            playerName: 'Alex',
            value: 60,
            isDouble: false,
            isTriple: true,
            isBust: false,
            score: 51,
            roundNumber: 2,
            timestamp: '2026-02-13T09:00:00+00:00',
        );

        $ack = $service->buildUndoAck($game, $undoneThrow);

        self::assertSame('state-v3', $ack->stateVersion);
        self::assertSame($undoneThrow, $ack->undoneThrow);
        self::assertSame('started', $ack->scoreboardDelta->status);
        self::assertSame(2, $ack->scoreboardDelta->currentRound);
        self::assertCount(1, $ack->scoreboardDelta->changedPlayers);
        self::assertNull($ack->scoreboardDelta->changedPlayers[0]->isBust);
    }

    /**
     * @throws ReflectionException
     */
    public function testBuildThrowAckBackfillsLatestThrowPlayerNameFromPreloadedRowsWhenMissing(): void
    {
        $game = (new Game())
            ->setGameId(77)
            ->setStatus(GameStatus::Started)
            ->setRound(4)
            ->setStartScore(301);

        $player = $this->createMock(User::class);
        $player->method('getId')
            ->willReturn(10);
        $player->expects(self::never())
            ->method('getDisplayNameRaw');
        $player->expects(self::never())
            ->method('getUsername');
        $player->expects(self::never())
            ->method('isGuest');

        $gamePlayer = (new GamePlayers())
            ->setDisplayNameSnapshot('Snapshot Name')
            ->setPosition(1)
            ->setScore(180)
            ->setPlayer($player);
        $game->addGamePlayer($gamePlayer);

        $roundThrowsRepository = $this->createStub(RoundThrowsRepositoryInterface::class);

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->expects(self::once())
            ->method('findGameStatePlayersByGameId')
            ->with(77)
            ->willReturn([
                [
                    'playerId' => 10,
                    'name' => 'Snapshot Name',
                    'position' => 1,
                    'score' => 180,
                    'isGuest' => false,
                ],
            ]);

        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->expects(self::once())
            ->method('buildStateVersion')
            ->with($game, 801)
            ->willReturn('state-v5');
        $gameService->expects(self::once())
            ->method('calculateActivePlayer')
            ->with($game)
            ->willReturn(10);

        $service = new GameDeltaService($roundThrowsRepository, $gameService, $gamePlayersRepository);
        $ack = $service->buildThrowAck($game, [
            'id' => 801,
            'playerId' => 10,
            'playerName' => '',
            'value' => 20,
            'isDouble' => false,
            'isTriple' => false,
            'isBust' => false,
            'score' => 180,
            'roundNumber' => 4,
            'timestamp' => '2026-03-10T10:00:00+00:00',
        ]);

        self::assertNotNull($ack->throw);
        self::assertSame('Snapshot Name', $ack->throw->playerName);
        self::assertCount(1, $ack->scoreboardDelta->changedPlayers);
        self::assertSame('Snapshot Name', $ack->scoreboardDelta->changedPlayers[0]->name);
    }

    public function testBuildThrowAckUsesPreloadedScoreboardRowsAndKeepsIsGuest(): void
    {
        $game = (new Game())
            ->setGameId(88)
            ->setStatus(GameStatus::Started)
            ->setRound(1)
            ->setStartScore(301);

        $player = $this->createMock(User::class);
        $player->method('getId')
            ->willReturn(31);
        $player->expects(self::never())
            ->method('getDisplayNameRaw');
        $player->expects(self::never())
            ->method('getUsername');
        $player->expects(self::never())
            ->method('isGuest');

        $game->addGamePlayer(
            (new GamePlayers())
                ->setDisplayNameSnapshot('Guest Player')
                ->setPosition(1)
                ->setScore(250)
                ->setPlayer($player)
        );

        $roundThrowsRepository = $this->createStub(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findCurrentRoundThrowsForGamePlayers')
            ->with(88, 1)
            ->willReturn([]);

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->expects(self::once())
            ->method('findGameStatePlayersByGameId')
            ->with(88)
            ->willReturn([
                [
                    'playerId' => 31,
                    'name' => 'Guest Player',
                    'position' => 1,
                    'score' => 250,
                    'isGuest' => true,
                ],
            ]);

        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->expects(self::once())
            ->method('buildStateVersion')
            ->with($game, 901)
            ->willReturn('state-v6');
        $gameService->expects(self::once())
            ->method('calculateActivePlayer')
            ->with($game)
            ->willReturn(31);

        $service = new GameDeltaService($roundThrowsRepository, $gameService, $gamePlayersRepository);
        $ack = $service->buildThrowAck($game, [
            'id' => 901,
            'playerId' => 31,
            'playerName' => 'Guest Player',
            'value' => 51,
            'isDouble' => false,
            'isTriple' => false,
            'isBust' => false,
            'score' => 250,
            'roundNumber' => 1,
            'timestamp' => '2026-03-10T10:00:00+00:00',
        ]);

        self::assertCount(1, $ack->scoreboardDelta->changedPlayers);
        self::assertSame(31, $ack->scoreboardDelta->changedPlayers[0]->playerId);
        self::assertSame('Guest Player', $ack->scoreboardDelta->changedPlayers[0]->name);
        self::assertTrue($ack->scoreboardDelta->changedPlayers[0]->isGuest);
    }

    /**
     * @param object $object
     * @param string $property
     * @param mixed  $value
     *
     * @return void
     *
     * @throws ReflectionException
     */
    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty($object, $property);
        $ref->setAccessible(true);
        $ref->setValue($object, $value);
    }
}
