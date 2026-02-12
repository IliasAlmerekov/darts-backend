<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GameLifecycleController;
use App\Dto\GameSettingsRequest;
use App\Dto\StartGameRequest;
use App\Dto\GameResponseDto;
use App\Entity\Game;
use App\Exception\Game\GameMustHaveValidPlayerCountException;
use App\Exception\Game\NoSettingsProvidedException;
use App\Service\Game\GameFinishServiceInterface;
use App\Service\Game\GameReopenServiceInterface;
use App\Service\Game\GameRoomServiceInterface;
use App\Service\Game\GameServiceInterface;
use App\Service\Game\GameSettingsServiceInterface;
use App\Service\Game\GameStartServiceInterface;
use App\Service\Security\GameAccessServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AllowMockObjectsWithoutExpectations]
final class GameLifecycleControllerTest extends TestCase
{
    private GameLifecycleController $controller;
    private ContainerInterface&MockObject $container;

    protected function setUp(): void
    {
        $this->controller = new GameLifecycleController();
        $this->container = $this->createMock(ContainerInterface::class);
        $this->controller->setContainer($this->container);
    }

    public function testStartSuccess(): void
    {
        $game = $this->createMock(Game::class);
        $dto = new StartGameRequest();
        $startService = $this->createMock(GameStartServiceInterface::class);
        $startService->expects($this->once())->method('start')->with($game, $dto);

        $response = $this->controller->start($game, $startService, $dto);

        $this->assertSame($game, $response);
    }

    public function testStartReturnsBadRequestOnError(): void
    {
        $game = $this->createMock(Game::class);
        $dto = new StartGameRequest();
        $startService = $this->createMock(GameStartServiceInterface::class);
        $startService->method('start')->willThrowException(new GameMustHaveValidPlayerCountException());

        $this->expectException(GameMustHaveValidPlayerCountException::class);
        $this->controller->start($game, $startService, $dto);
    }

    public function testCreateSettingsCreatesGame(): void
    {
        $dto = new GameSettingsRequest();
        $game = $this->createMock(Game::class);

        $roomService = $this->createMock(GameRoomServiceInterface::class);
        $roomService->method('createGame')->willReturn($game);

        $settingsService = $this->createMock(GameSettingsServiceInterface::class);
        $settingsService->expects($this->once())->method('updateSettings')->with($game, $dto);

        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->method('createGameDto')->willReturn($this->dummyGameDto());
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn(callable $func) => $func());

        $response = $this->controller->createSettings($roomService, $settingsService, $gameService, $entityManager, $dto);

        $this->assertInstanceOf(GameResponseDto::class, $response);
    }

    public function testUpdateSettingsReturnsBadRequestOnError(): void
    {
        $dto = new GameSettingsRequest();
        $game = $this->createMock(Game::class);
        $settingsService = $this->createMock(GameSettingsServiceInterface::class);
        $settingsService->method('updateSettings')->willThrowException(new NoSettingsProvidedException());
        $gameService = $this->createMock(GameServiceInterface::class);

        $this->expectException(NoSettingsProvidedException::class);
        $this->controller->updateSettings($game, $settingsService, $gameService, $dto);
    }

    public function testFinishedReturnsResult(): void
    {
        $game = $this->createMock(Game::class);
        $finishService = $this->createMock(GameFinishServiceInterface::class);
        $finishService->method('getFinishedPlayers')->willReturn([
            [
                'playerId' => 1,
                'username' => 'player',
                'position' => 1,
                'roundsPlayed' => 5,
                'roundAverage' => 60.0,
            ],
        ]);

        $response = $this->controller->finished($game, $finishService);

        $this->assertIsArray($response);
    }

    public function testReopenReturnsGameState(): void
    {
        $game = $this->createMock(Game::class);
        $reopenService = $this->createMock(GameReopenServiceInterface::class);
        $reopenService->expects($this->once())->method('reopen')->with($game);

        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->method('createGameDto')->willReturn($this->dummyGameDto());

        $response = $this->controller->reopen($game, $reopenService, $gameService);

        $this->assertInstanceOf(GameResponseDto::class, $response);
    }

    public function testGetGameStateReturnsJsonWithVersionHeaders(): void
    {
        $game = $this->createMock(Game::class);
        $gameAccessService = $this->createMock(GameAccessServiceInterface::class);
        $gameAccessService->expects($this->once())
            ->method('assertPlayerInGameOrAdmin')
            ->with($game);
        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->expects($this->once())
            ->method('buildStateVersion')
            ->with($game)
            ->willReturn('state-v1');
        $gameService->expects($this->once())
            ->method('createGameDto')
            ->with($game)
            ->willReturn($this->dummyGameDto());

        $request = new Request();
        $response = $this->controller->getGameState($game, $gameAccessService, $gameService, $request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('state-v1', $response->headers->get('X-Game-State-Version'));
        $this->assertSame('"state-v1"', $response->headers->get('ETag'));
    }

    public function testGetGameStateReturnsNotModifiedWhenSinceMatches(): void
    {
        $game = $this->createMock(Game::class);
        $gameAccessService = $this->createMock(GameAccessServiceInterface::class);
        $gameAccessService->expects($this->once())
            ->method('assertPlayerInGameOrAdmin')
            ->with($game);
        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->expects($this->once())
            ->method('buildStateVersion')
            ->with($game)
            ->willReturn('state-v1');
        $gameService->expects($this->never())
            ->method('createGameDto');

        $request = new Request();
        $response = $this->controller->getGameState($game, $gameAccessService, $gameService, $request, 'state-v1');

        $this->assertSame(Response::HTTP_NOT_MODIFIED, $response->getStatusCode());
        $this->assertSame('state-v1', $response->headers->get('X-Game-State-Version'));
    }

    public function testGetGameStateReturnsNotModifiedWhenIfNoneMatchMatches(): void
    {
        $game = $this->createMock(Game::class);
        $gameAccessService = $this->createMock(GameAccessServiceInterface::class);
        $gameAccessService->expects($this->once())
            ->method('assertPlayerInGameOrAdmin')
            ->with($game);
        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->expects($this->once())
            ->method('buildStateVersion')
            ->with($game)
            ->willReturn('state-v1');
        $gameService->expects($this->never())
            ->method('createGameDto');

        $request = new Request();
        $request->headers->set('If-None-Match', '"state-v1"');

        $response = $this->controller->getGameState($game, $gameAccessService, $gameService, $request);

        $this->assertSame(Response::HTTP_NOT_MODIFIED, $response->getStatusCode());
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
}
