<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\StartGameRequest;
use App\Entity\Game;
use App\Exception\Game\GameNotFoundException;
use App\Service\Game\GameRoomServiceInterface;
use App\Service\Game\GameStartServiceInterface;
use App\Service\Game\RematchServiceInterface;
use App\Service\Game\RematchStartService;
use LogicException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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
}