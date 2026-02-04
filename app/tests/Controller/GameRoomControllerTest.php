<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GameRoomController;
use App\Entity\Game;
use App\Dto\RoomCreateRequest;
use App\Service\Game\GameRoomServiceInterface;
use App\Service\Player\PlayerManagementServiceInterface;
use App\Service\Player\GuestPlayerServiceInterface;
use App\Service\Game\RematchServiceInterface;
use App\Service\Sse\SseStreamServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

#[AllowMockObjectsWithoutExpectations]
class GameRoomControllerTest extends TestCase
{
    private GameRoomServiceInterface&MockObject $gameRoomService;
    private PlayerManagementServiceInterface&MockObject $playerManagementService;
    private RematchServiceInterface&MockObject $rematchService;
    private GuestPlayerServiceInterface&MockObject $guestPlayerService;
    private SseStreamServiceInterface&MockObject $sseStreamService;
    private GameRoomController $controller;
    private ContainerInterface&MockObject $container;

    protected function setUp(): void
    {
        // Mock aller Services
        $this->gameRoomService = $this->createMock(GameRoomServiceInterface::class);
        $this->playerManagementService = $this->createMock(PlayerManagementServiceInterface::class);
        $this->rematchService = $this->createMock(RematchServiceInterface::class);
        $this->guestPlayerService = $this->createMock(GuestPlayerServiceInterface::class);
        $this->sseStreamService = $this->createMock(SseStreamServiceInterface::class);

        // Controller mit allen Dependencies instanziieren
        $this->controller = new GameRoomController(
            $this->gameRoomService,
            $this->playerManagementService,
            $this->rematchService,
            $this->guestPlayerService,
            $this->sseStreamService
        );

        // Container für AbstractController-Methoden
        $this->container = $this->createMock(ContainerInterface::class);
        $this->controller->setContainer($this->container);
    }

    /**
     * Test: POST Request ohne Parameter -> Game wird erstellt
     */
    public function testRoomCreatePostCreatesGame(): void
    {
        $gameMock = $this->createMock(Game::class);
        $gameMock->method('getGameId')->willReturn(123);

        $this->gameRoomService->expects($this->once())
            ->method('createGameWithPreviousPlayers')
            ->with(null, null, null)
            ->willReturn($gameMock);

        $this->container->method('has')->willReturn(false);

        $dto = new RoomCreateRequest();

        $response = $this->controller->roomCreateApi($dto);

        $this->assertIsArray($response);
    }
    public function testRematchReturnsSuccess(): void
    {
        $gameId = 42;
        $expectedResult = [
            'success' => true,
            'gameId' => 99,
            'message' => 'Rematch created'
        ];

        $this->rematchService->expects($this->once())
            ->method('createRematch')
            ->with($gameId)
            ->willReturn($expectedResult);

        $response = $this->controller->rematch($gameId);

        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertEquals(99, $response['gameId']);
    }
}
