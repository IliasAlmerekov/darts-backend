<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GameThrowController;
use App\Dto\ThrowRequest;
use App\Dto\GameResponseDto;
use App\Entity\Game;
use App\Service\Game\GameServiceInterface;
use App\Service\Game\GameThrowServiceInterface;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

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
        $game = $this->createMock(Game::class);
        $dto = new ThrowRequest();

        $throwService = $this->createMock(GameThrowServiceInterface::class);
        $throwService->expects($this->once())->method('recordThrow')->with($game, $dto);

        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->method('createGameDto')->willReturn($this->dummyGameDto());

        $response = $this->controller->throw($game, $throwService, $gameService, $dto);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testThrowReturnsBadRequestOnInvalidArgument(): void
    {
        $game = $this->createMock(Game::class);
        $dto = new ThrowRequest();
        $throwService = $this->createMock(GameThrowServiceInterface::class);
        $throwService->method('recordThrow')->willThrowException(new InvalidArgumentException('bad'));
        $gameService = $this->createMock(GameServiceInterface::class);

        $response = $this->controller->throw($game, $throwService, $gameService, $dto);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testUndoThrowSuccess(): void
    {
        $game = $this->createMock(Game::class);
        $throwService = $this->createMock(GameThrowServiceInterface::class);
        $throwService->expects($this->once())->method('undoLastThrow')->with($game);

        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->method('createGameDto')->willReturn($this->dummyGameDto());

        $response = $this->controller->undoThrow($game, $throwService, $gameService);

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
