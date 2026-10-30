<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GameThrowController;
use App\Dto\ScoreboardDeltaDto;
use App\Dto\ThrowAckDto;
use App\Dto\ThrowDeltaDto;
use App\Dto\ThrowRequest;
use App\Dto\UndoAckDto;
use App\Entity\Game;
use App\Exception\Game\GameNotFoundException;
use App\Service\Game\GameStateReaderInterface;
use App\Service\Game\GameThrowServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[AllowMockObjectsWithoutExpectations]
final class GameThrowControllerTest extends TestCase
{
    private GameThrowController $controller;
    private ContainerInterface&MockObject $container;

    protected function setUp(): void
    {
        $this->controller = new GameThrowController();
        $this->container = $this->createMock(ContainerInterface::class);
        $this->controller->setContainer($this->container);
    }

    public function testUndoThrowDeltaSuccess(): void
    {
        $game = $this->createMock(Game::class);
        $throwService = $this->createMock(GameThrowServiceInterface::class);
        $undoAck = $this->dummyUndoAckDto();
        $deltaService = $this->createMock(GameStateReaderInterface::class);
        $throwService->expects($this->once())
            ->method('undoThrowAck')
            ->with($game, $deltaService)
            ->willReturn($undoAck);

        $response = $this->controller->undoThrowDelta($game, $throwService, $deltaService);

        $this->assertInstanceOf(UndoAckDto::class, $response);
        $this->assertSame($undoAck, $response);
    }

    public function testThrowDeltaSuccess(): void
    {
        $dto = new ThrowRequest();
        $throwService = $this->createMock(GameThrowServiceInterface::class);
        $deltaService = $this->createMock(GameStateReaderInterface::class);
        $throwService->expects(self::once())
            ->method('recordThrowAck')
            ->with(777, $dto, $deltaService)
            ->willReturn($this->dummyThrowAckDto());

        $response = $this->controller->throwDelta(777, $throwService, $deltaService, $dto);

        self::assertInstanceOf(ThrowAckDto::class, $response);
        self::assertSame(777, $response->gameId);
    }

    public function testThrowDeltaKeepsNotFoundBehaviorWhenServiceCannotLoadGame(): void
    {
        $dto = new ThrowRequest();
        $throwService = $this->createMock(GameThrowServiceInterface::class);
        $deltaService = $this->createMock(GameStateReaderInterface::class);
        $throwService->expects(self::once())
            ->method('recordThrowAck')
            ->with(777, $dto, $deltaService)
            ->willThrowException(new GameNotFoundException());

        $this->expectException(NotFoundHttpException::class);
        $this->controller->throwDelta(777, $throwService, $deltaService, $dto);
    }

    private function dummyThrowAckDto(): ThrowAckDto
    {
        return new ThrowAckDto(
            success: true,
            gameId: 777,
            stateVersion: 'state-v1',
            throw: null,
            scoreboardDelta: new ScoreboardDeltaDto(
                changedPlayers: [],
                winnerId: null,
                status: 'started',
                currentRound: 1,
            ),
            serverTs: '2026-02-13T00:00:00+00:00',
        );
    }

    private function dummyUndoAckDto(?ThrowDeltaDto $undoneThrow = null): UndoAckDto
    {
        return new UndoAckDto(
            success: true,
            gameId: 777,
            stateVersion: 'state-v2',
            undoneThrow: $undoneThrow,
            scoreboardDelta: new ScoreboardDeltaDto(
                changedPlayers: [],
                winnerId: null,
                status: 'started',
                currentRound: 2,
            ),
            serverTs: '2026-02-13T00:00:00+00:00',
        );
    }

}
