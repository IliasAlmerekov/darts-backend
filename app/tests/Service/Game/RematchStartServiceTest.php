<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\StartGameRequest;
use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Exception\Game\GameNotFoundException;
use App\Repository\GameRepositoryInterface;
use App\Service\Game\GameRoomServiceInterface;
use App\Service\Game\GameStartServiceInterface;
use App\Service\Game\RematchServiceInterface;
use App\Service\Game\RematchStartService;
use LogicException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[AllowMockObjectsWithoutExpectations]
final class RematchStartServiceTest extends TestCase
{
    private RematchServiceInterface&MockObject $rematchService;
    private GameRoomServiceInterface&MockObject $gameRoomService;
    private GameStartServiceInterface&MockObject $gameStartService;
    private RematchStartService $service;

    protected function setUp(): void
    {
        $this->rematchService = $this->createMock(RematchServiceInterface::class);
        $this->gameRoomService = $this->createMock(GameRoomServiceInterface::class);
        $this->gameStartService = $this->createMock(GameStartServiceInterface::class);

        $this->service = new RematchStartService(
            $this->rematchService,
            $this->gameRoomService,
            $this->gameStartService,
            $this->createStub(GameRepositoryInterface::class),
        );
    }

    public function testCreateAndStartThrowsWhenPreviousGameIsMissing(): void
    {
        $dto = new StartGameRequest();
        $this->rematchService->expects(self::once())
            ->method('createRematch')
            ->with(42)
            ->willReturn([
                'success' => false,
                'message' => 'Previous game not found',
            ]);

        $this->gameRoomService->expects(self::never())->method('findGameById');
        $this->gameStartService->expects(self::never())->method('start');

        $this->expectException(GameNotFoundException::class);
        $this->service->createAndStart(42, $dto);
    }

    public function testCreateAndStartStartsNewRematchGame(): void
    {
        $dto = new StartGameRequest();
        $game = new Game();

        $this->rematchService->expects(self::once())
            ->method('createRematch')
            ->with(42)
            ->willReturn([
                'success' => true,
                'gameId' => 77,
            ]);

        $this->gameRoomService->expects(self::once())
            ->method('findGameById')
            ->with(77)
            ->willReturn($game);

        $this->gameStartService->expects(self::once())
            ->method('start')
            ->with($game, $dto);

        self::assertSame($game, $this->service->createAndStart(42, $dto));
    }

    public function testCreateAndStartThrowsWhenRematchGameIdIsMissing(): void
    {
        $dto = new StartGameRequest();
        $this->rematchService->expects(self::once())
            ->method('createRematch')
            ->with(42)
            ->willReturn([
                'success' => true,
            ]);

        $this->gameRoomService->expects(self::never())->method('findGameById');
        $this->gameStartService->expects(self::never())->method('start');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Rematch service did not return a valid gameId.');
        $this->service->createAndStart(42, $dto);
    }

    public function testLobbyParticipantsWithTheSamePositionFollowSeatOrder(): void
    {
        $game = new Game();
        $game->addGamePlayer($this->seat(9, 1, 101));
        $game->addGamePlayer($this->seat(4, 1, 102));
        $dto = new StartGameRequest();
        $dto->playerPositions = [];

        $this->rematchService->method('createRematch')->willReturn(['success' => true, 'gameId' => 77]);
        $this->gameRoomService->method('findGameById')->willReturn($game);
        $this->gameStartService->expects(self::once())
            ->method('start')
            ->with($game, self::callback(static fn (StartGameRequest $sent): bool => [102, 101] === $sent->playerPositions));

        $this->service->createAndStart(42, $dto);
    }

    private function seat(int $seatId, int $position, int $userId): GamePlayers
    {
        $user = new User();
        (new ReflectionProperty(User::class, 'id'))->setValue($user, $userId);
        $seat = (new GamePlayers())->setPlayer($user)->setPosition($position);
        (new ReflectionProperty(GamePlayers::class, 'gamePlayerId'))->setValue($seat, $seatId);

        return $seat;
    }
}
