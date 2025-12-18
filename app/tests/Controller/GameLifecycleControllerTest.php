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
use App\Service\Game\GameRoomServiceInterface;
use App\Service\Game\GameServiceInterface;
use App\Service\Game\GameSettingsServiceInterface;
use App\Service\Game\GameStartServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
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

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
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

        $response = $this->controller->createSettings($roomService, $settingsService, $gameService, $dto);

        $this->assertEquals(Response::HTTP_CREATED, $response->getStatusCode());
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
        $finishService->method('finishGame')->willReturn(['ok' => true]);

        $response = $this->controller->finished($game, $finishService);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testGetGameStateReturnsDto(): void
    {
        $game = $this->createMock(Game::class);
        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->method('createGameDto')->willReturn($this->dummyGameDto());

        $response = $this->controller->getGameState($game, $gameService);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
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
