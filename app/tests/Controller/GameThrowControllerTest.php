<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GameThrowController;
use App\Dto\ThrowRequest;
use App\Dto\GameResponseDto;
use App\Entity\Game;
use App\Exception\Game\PlayerAlreadyThrewThreeTimesException;
use App\Service\Game\GameServiceInterface;
use App\Service\Game\GameThrowServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

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
        $game = $this->createMock(Game::class);
        $dto = new ThrowRequest();

        $throwService = $this->createMock(GameThrowServiceInterface::class);
        $throwService->expects($this->once())->method('recordThrow')->with($game, $dto);

        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->method('createGameDto')->willReturn($this->dummyGameDto());

        $response = $this->controller->throw($game, $throwService, $gameService, $dto);

        $this->assertInstanceOf(GameResponseDto::class, $response);
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
        $game = $this->createMock(Game::class);
        $throwService = $this->createMock(GameThrowServiceInterface::class);
        $throwService->expects($this->once())->method('undoLastThrow')->with($game);

        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->method('createGameDto')->willReturn($this->dummyGameDto());

        $response = $this->controller->undoThrow($game, $throwService, $gameService);

        $this->assertInstanceOf(GameResponseDto::class, $response);
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
