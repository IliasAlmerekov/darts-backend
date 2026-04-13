<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GameThrowController;
use App\Dto\GameResponseDto;
use App\Dto\ScoreboardDeltaDto;
use App\Dto\ThrowAckDto;
use App\Dto\ThrowRecordingResultDto;
use App\Dto\ThrowDeltaDto;
use App\Dto\ThrowRequest;
use App\Dto\UndoAckDto;
use App\Entity\Game;
use App\Exception\Game\GameNotFoundException;
use App\Exception\Game\PlayerAlreadyThrewThreeTimesException;
use App\Service\Game\GameDeltaServiceInterface;
use App\Service\Game\GameServiceInterface;
use App\Service\Game\GameThrowServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
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

    public function testThrowSuccess(): void
    {
        $game = (new Game())->setGameId(123);
        $dto = new ThrowRequest();

        $throwService = $this->createMock(GameThrowServiceInterface::class);
        $throwService->expects($this->once())
            ->method('recordThrow')
            ->with($game, $dto)
            ->willReturn($this->dummyThrowRecordingResultDto($game));

        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->method('createGameDto')->willReturn($this->dummyGameDto());

        $response = $this->controller->throw($game, $throwService, $gameService, $dto);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('true', $response->headers->get('Deprecation'));
        $this->assertSame('Wed, 30 Sep 2026 23:59:59 GMT', $response->headers->get('Sunset'));
        $this->assertSame('</api/game/123/throw/delta>; rel="successor-version"', $response->headers->get('Link'));
    }

    public function testThrowReturnsBadRequestOnInvalidArgument(): void
    {
        $game = $this->createMock(Game::class);
        $dto = new ThrowRequest();
        $throwService = $this->createMock(GameThrowServiceInterface::class);
        $throwService->method('recordThrow')->willThrowException(new PlayerAlreadyThrewThreeTimesException());
        $gameService = $this->createMock(GameServiceInterface::class);

        $this->expectException(PlayerAlreadyThrewThreeTimesException::class);
        $this->controller->throw($game, $throwService, $gameService, $dto);
    }

    public function testUndoThrowSuccess(): void
    {
        $game = (new Game())->setGameId(123);
        $throwService = $this->createMock(GameThrowServiceInterface::class);
        $throwService->expects($this->once())->method('undoLastThrow')->with($game);

        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->expects(self::once())
            ->method('createGameDto')
            ->with($game)
            ->willReturn($this->dummyGameDto());

        $response = $this->controller->undoThrow($game, $throwService, $gameService);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('true', $response->headers->get('Deprecation'));
        $this->assertSame('Wed, 30 Sep 2026 23:59:59 GMT', $response->headers->get('Sunset'));
        $this->assertSame('</api/game/123/throw/delta>; rel="successor-version"', $response->headers->get('Link'));
    }

    public function testUndoThrowDeltaSuccess(): void
    {
        $game = $this->createMock(Game::class);
        $throwService = $this->createMock(GameThrowServiceInterface::class);
        $undoneThrow = new ThrowDeltaDto(
            id: 501,
            playerId: 10,
            playerName: 'Alex',
            value: 25,
            isDouble: false,
            isTriple: false,
            isBust: true,
            score: 26,
            roundNumber: 2,
            timestamp: '2026-02-13T09:00:00+00:00',
        );
        $throwService->expects($this->once())
            ->method('undoLastThrow')
            ->with($game)
            ->willReturn($undoneThrow);

        $deltaService = $this->createMock(GameDeltaServiceInterface::class);
        $deltaService->expects(self::once())
            ->method('buildUndoAck')
            ->with($game, $undoneThrow)
            ->willReturn($this->dummyUndoAckDto($undoneThrow));

        $response = $this->controller->undoThrowDelta($game, $throwService, $deltaService);

        $this->assertInstanceOf(UndoAckDto::class, $response);
        $this->assertSame($undoneThrow, $response->undoneThrow);
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

        $deltaService = $this->createMock(GameDeltaServiceInterface::class);
        $deltaService->expects(self::once())
            ->method('buildThrowAck')
            ->with($game, $throwRecordingResult->latestThrow, $throwRecordingResult->currentRoundStateSnapshot)
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
        $deltaService = $this->createMock(GameDeltaServiceInterface::class);

        $this->expectException(NotFoundHttpException::class);
        $this->controller->throwDelta(777, $throwService, $deltaService, $dto);
    }

    private function dummyGameDto(): GameResponseDto
    {
        return new GameResponseDto(
            id: 1,
            status: 'started',
            currentRound: 1,
            activePlayerId: 1,
            currentThrowCount: 0,
            players: [],
            winnerId: null,
            settings: []
        );
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
        return new ThrowRecordingResultDto(
            latestThrow: [
                'id' => 501,
                'playerId' => 10,
                'playerName' => 'Alex',
                'value' => 25,
                'isDouble' => false,
                'isTriple' => false,
                'isBust' => true,
                'score' => 26,
                'roundNumber' => 2,
                'throwNumber' => 1,
                'timestamp' => '2026-02-13T09:00:00+00:00',
            ],
            currentRoundStateSnapshot: [
                10 => [
                    'throwsCount' => 1,
                    'lastThrowNumber' => 1,
                    'lastThrowValue' => 25,
                    'lastThrowBust' => true,
                ],
            ],
            game: $game,
        );
    }
}
