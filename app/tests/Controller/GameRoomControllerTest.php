<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GameRoomController;
use App\Entity\Game;
use App\Service\GameRoomServiceInterface;
use App\Service\PlayerManagementServiceInterface;
use App\Service\RematchServiceInterface;
use App\Service\SseStreamServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class GameRoomControllerTest extends TestCase
{
    private GameRoomServiceInterface&MockObject $gameRoomService;
    private PlayerManagementServiceInterface&MockObject $playerManagementService;
    private RematchServiceInterface&MockObject $rematchService;
    private SseStreamServiceInterface&MockObject $sseStreamService;
    private GameRoomController $controller;
    private ContainerInterface&MockObject $container;

    protected function setUp(): void
    {
        // Mock aller 4 Services
        $this->gameRoomService = $this->createMock(GameRoomServiceInterface::class);
        $this->playerManagementService = $this->createMock(PlayerManagementServiceInterface::class);
        $this->rematchService = $this->createMock(RematchServiceInterface::class);
        $this->sseStreamService = $this->createMock(SseStreamServiceInterface::class);

        // Controller mit allen 4 Dependencies instanziieren
        $this->controller = new GameRoomController(
            $this->gameRoomService,
            $this->playerManagementService,
            $this->rematchService,
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
        $request = Request::create(
            uri: '/api/room/create',
            method: 'POST',
            parameters: [],
            cookies: [],
            files: [],
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([])
        );

        $gameMock = $this->createMock(Game::class);
        $gameMock->method('getGameId')->willReturn(123);

        $this->gameRoomService->expects($this->once())
            ->method('createGameWithPreviousPlayers')
            ->with(null, null, null)
            ->willReturn($gameMock);

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('');
        $this->container->method('has')->willReturnMap([['twig', true]]);
        $this->container->method('get')->with('twig')->willReturn($twig);

        $response = $this->controller->roomCreate($request);

        $this->assertInstanceOf(Response::class, $response);
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

        // Container Mock (für $this->json())
        $this->container->method('has')->willReturn(false);

        $response = $this->controller->rematch($gameId);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_CREATED, $response->getStatusCode()); // 201

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertEquals(99, $data['gameId']);
    }
}
