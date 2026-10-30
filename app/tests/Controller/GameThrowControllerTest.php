<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GameThrowController;
use App\Dto\ScoreboardDeltaDto;
use App\Dto\ThrowAckDto;
use App\Dto\ThrowRecordingResultDto;
use App\Dto\ThrowDeltaDto;
use App\Dto\ThrowRequest;
use App\Dto\UndoAckDto;
use App\Entity\Game;
use App\Entity\RoundThrows;
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
        $undoneThrow = new RoundThrows();
        $undoAck = $this->dummyUndoAckDto();
        $throwService->expects($this->once())
            ->method('undoLastThrow')
            ->with($game)
            ->willReturn($undoneThrow);

        $deltaService = $this->createMock(GameStateReaderInterface::class);
        $deltaService->expects(self::once())
            ->method('buildUndoAck')
            ->with($game, $undoneThrow)
            ->willReturn($undoAck);

        $response = $this->controller->undoThrowDelta($game, $throwService, $deltaService);

        $this->assertInstanceOf(UndoAckDto::class, $response);
        $this->assertSame($undoAck, $response);
    }

    public function testThrowDeltaSuccess(): void
    {
        $game = (new Game())->setGameId(777);
        $dto = new ThrowRequest();
        $throwRecordingResult = $this->dummyThrowRecordingResultDto($game);
        $throwService = $this->createMock(GameThrowServiceInterface::class);
        $throwService->expects(self::once())
            ->method('recordThrowByGameId')
            ->with(777, $dto)
            ->willReturn($throwRecordingResult);

        $deltaService = $this->createMock(GameStateReaderInterface::class);
        $deltaService->expects(self::once())
            ->method('buildThrowAck')
            ->with($game, $throwRecordingResult->latestThrow)
            ->willReturn($this->dummyThrowAckDto());

        $response = $this->controller->throwDelta(777, $throwService, $deltaService, $dto);

        self::assertInstanceOf(ThrowAckDto::class, $response);
        self::assertSame(777, $response->gameId);
    }

    public function testThrowDeltaKeepsNotFoundBehaviorWhenServiceCannotLoadGame(): void
    {
        $dto = new ThrowRequest();
        $throwService = $this->createMock(GameThrowServiceInterface::class);
        $throwService->expects(self::once())
            ->method('recordThrowByGameId')
            ->with(777, $dto)
            ->willThrowException(new GameNotFoundException());
        $deltaService = $this->createMock(GameStateReaderInterface::class);

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

    private function dummyThrowRecordingResultDto(Game $game): ThrowRecordingResultDto
    {
        return new ThrowRecordingResultDto(latestThrow: new RoundThrows(), game: $game);
    }
}
