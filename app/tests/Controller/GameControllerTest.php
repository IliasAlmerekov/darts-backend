<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GameController;
use App\Dto\GameResponseDto;
use App\Dto\StartGameRequest;
use App\Dto\ThrowRequest;
use App\Entity\Game;
use App\Repository\GameRepositoryInterface;
use App\Service\GameServiceInterface;
use App\Service\GameStartServiceInterface;
use App\Service\GameThrowServiceInterface;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;

class GameControllerTest extends TestCase
{
    private GameController $controller;
    private ContainerInterface&MockObject $container;

    protected function setUp(): void
    {
        $this->controller = new GameController();
        $this->container = $this->createMock(ContainerInterface::class);
        $this->controller->setContainer($this->container);
    }

    /**
     * Helper: Erstellt eine ThrowRequest-Instanz
     */
    private function createThrowRequest(
        int $playerId,
        int $value,
        bool $isDouble = false,
        bool $isTriple = false,
        bool $isBust = false
    ): ThrowRequest {
        $dto = new ThrowRequest();
        $dto->playerId = $playerId;
        $dto->value = $value;
        $dto->isDouble = $isDouble;
        $dto->isTriple = $isTriple;
        $dto->isBust = $isBust;
        return $dto;
    }

    /**
     * Helper: Erstellt ein GameResponseDto
     */
    private function createGameResponseDto(
        int $id,
        string $status = 'playing',
        int $currentRound = 1,
        ?int $activePlayerId = null,
        int $currentThrowCount = 0,
        array $players = [],
        ?int $winnerId = null,
        array $settings = []
    ): GameResponseDto {
        return new GameResponseDto(
            id: $id,
            status: $status,
            currentRound: $currentRound,
            activePlayerId: $activePlayerId,
            currentThrowCount: $currentThrowCount,
            players: $players,
            winnerId: $winnerId,
            settings: array_merge([
                'startScore' => 301,
                'doubleOut' => false,
                'tripleOut' => false,
            ], $settings)
        );
    }

    /**
     * Helper: Erstellt eine StartGameRequest-Instanz
     */
    private function createStartGameRequest(
        int $startScore = 301,
        bool $doubleOut = false,
        bool $tripleOut = false
    ): StartGameRequest {
        $dto = new StartGameRequest();
        $dto->startScore = $startScore;
        $dto->doubleOut = $doubleOut;
        $dto->tripleOut = $tripleOut;
        return $dto;
    }

    /**
     * Test: Erfolgreicher Throw - Game gefunden, Throw aufgezeichnet
     */
    public function testThrowSuccessfullyRecordsThrow(): void
    {
        $gameId = 42;

        $jsonContent = json_encode([
            'playerId' => 1,
            'value' => 20,
            'isDouble' => true,
            'isTriple' => false,
            'isBust' => false
        ]);

        $request = Request::create(
            uri: "/api/game/{$gameId}/throw",
            method: 'POST',
            parameters: [],
            cookies: [],
            files: [],
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $jsonContent
        );

        $throwRequest = $this->createThrowRequest(
            playerId: 1,
            value: 20,
            isDouble: true
        );

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects($this->once())
            ->method('deserialize')
            ->with($jsonContent, ThrowRequest::class, 'json')
            ->willReturn($throwRequest);

        $game = $this->createMock(Game::class);

        $gameRepository = $this->createMock(GameRepositoryInterface::class);
        $gameRepository->expects($this->once())
            ->method('find')
            ->with($gameId)
            ->willReturn($game);

        $gameThrowService = $this->createMock(GameThrowServiceInterface::class);
        $gameThrowService->expects($this->once())
            ->method('recordThrow')
            ->with($game, $throwRequest);

        $gameDto = $this->createGameResponseDto(
            id: $gameId,
            status: 'playing',
            activePlayerId: 1,
            currentThrowCount: 1
        );

        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->expects($this->once())
            ->method('createGameDto')
            ->with($game)
            ->willReturn($gameDto);

        $this->container->method('has')->willReturn(false);

        $response = $this->controller->throw(
            $gameId,
            $request,
            $gameRepository,
            $gameThrowService,
            $gameService,
            $serializer
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals($gameId, $data['id']);
        $this->assertEquals('playing', $data['status']);
        $this->assertEquals(1, $data['activePlayerId']);
    }

    /**
     * Test: Throw - Game nicht gefunden -> 404 Response
     */
    public function testThrowReturns404WhenGameNotFound(): void
    {
        $gameId = 999;

        $request = Request::create(
            uri: "/api/game/{$gameId}/throw",
            method: 'POST',
            parameters: [],
            cookies: [],
            files: [],
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['playerId' => 1, 'value' => 60])
        );

        $throwRequest = $this->createThrowRequest(1, 60);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('deserialize')->willReturn($throwRequest);

        $gameRepository = $this->createMock(GameRepositoryInterface::class);
        $gameRepository->expects($this->once())
            ->method('find')
            ->with($gameId)
            ->willReturn(null);

        $gameThrowService = $this->createMock(GameThrowServiceInterface::class);
        $gameThrowService->expects($this->never())->method('recordThrow');

        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->expects($this->never())->method('createGameDto');

        $this->container->method('has')->willReturn(false);

        $response = $this->controller->throw(
            $gameId,
            $request,
            $gameRepository,
            $gameThrowService,
            $gameService,
            $serializer
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Game not found', $data['error']);
    }

    /**
     * Test: Throw - InvalidArgumentException -> 400 Bad Request
     */
    public function testThrowReturns400OnInvalidArgument(): void
    {
        $gameId = 42;
        $errorMessage = 'Player not found in this game';

        $request = Request::create(
            uri: "/api/game/{$gameId}/throw",
            method: 'POST',
            parameters: [],
            cookies: [],
            files: [],
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['playerId' => 999, 'value' => 20])
        );

        $throwRequest = $this->createThrowRequest(999, 20);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('deserialize')->willReturn($throwRequest);

        $game = $this->createMock(Game::class);

        $gameRepository = $this->createMock(GameRepositoryInterface::class);
        $gameRepository->method('find')->willReturn($game);

        $gameThrowService = $this->createMock(GameThrowServiceInterface::class);
        $gameThrowService->expects($this->once())
            ->method('recordThrow')
            ->with($game, $throwRequest)
            ->willThrowException(new InvalidArgumentException($errorMessage));

        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->expects($this->never())->method('createGameDto');

        $this->container->method('has')->willReturn(false);

        $response = $this->controller->throw(
            $gameId,
            $request,
            $gameRepository,
            $gameThrowService,
            $gameService,
            $serializer
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals($errorMessage, $data['error']);
    }

    /**
     * Test: Throw - Triple 20 (60 Punkte)
     */
    public function testThrowRecordsTriple20(): void
    {
        $gameId = 10;

        $request = Request::create(
            uri: "/api/game/{$gameId}/throw",
            method: 'POST',
            parameters: [],
            cookies: [],
            files: [],
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'playerId' => 2,
                'value' => 20,
                'isTriple' => true
            ])
        );

        $throwRequest = $this->createThrowRequest(
            playerId: 2,
            value: 20,
            isTriple: true
        );

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('deserialize')->willReturn($throwRequest);

        $game = $this->createMock(Game::class);
        $gameRepository = $this->createMock(GameRepositoryInterface::class);
        $gameRepository->method('find')->willReturn($game);

        $gameThrowService = $this->createMock(GameThrowServiceInterface::class);
        $gameThrowService->expects($this->once())->method('recordThrow');

        $gameDto = $this->createGameResponseDto(id: $gameId);
        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->method('createGameDto')->willReturn($gameDto);

        $this->container->method('has')->willReturn(false);

        $response = $this->controller->throw(
            $gameId,
            $request,
            $gameRepository,
            $gameThrowService,
            $gameService,
            $serializer
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    /**
     * Test: Start - Spiel erfolgreich starten
     */
    public function testStartSuccessfullyStartsGame(): void
    {
        $gameId = 42;

        $jsonContent = json_encode([
            'startScore' => 501,
            'doubleOut' => true,
            'tripleOut' => false
        ]);

        $request = Request::create(
            uri: "/api/game/{$gameId}/start",
            method: 'POST',
            parameters: [],
            cookies: [],
            files: [],
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $jsonContent
        );

        $startGameRequest = $this->createStartGameRequest(
            startScore: 501,
            doubleOut: true
        );

        $serializer = $this->createMock(SerializerInterface::class);

        $serializer->expects($this->once())
            ->method('deserialize')
            ->with($jsonContent, StartGameRequest::class, 'json')
            ->willReturn($startGameRequest);

        $serializer->expects($this->once())
            ->method('serialize')
            ->with(
                $this->isInstanceOf(Game::class),
                'json',
                $this->callback(function($context) {
                    // Prüfe nur das Wichtige
                    return is_array($context)
                        && isset($context['groups'])
                        && $context['groups'] === 'game:read';
                })
            )
            ->willReturn('{"id": 42, "status": "started"}');

        $game = $this->createMock(Game::class);

        $gameRepository = $this->createMock(GameRepositoryInterface::class);
        $gameRepository->expects($this->once())
            ->method('find')
            ->with($gameId)
            ->willReturn($game);

        $gameStartService = $this->createMock(GameStartServiceInterface::class);
        $gameStartService->expects($this->once())
            ->method('start')
            ->with($game, $startGameRequest);

        $this->container->method('has')->willReturnMap([
            ['serializer', true]
        ]);
        $this->container->method('get')->willReturnMap([
            ['serializer', $serializer]
        ]);

        $response = $this->controller->start(
            $gameId,
            $request,
            $gameRepository,
            $gameStartService,
            $serializer
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals(42, $data['id']);
        $this->assertEquals('started', $data['status']);
    }

    /**
     * Test: Start - Game nicht gefunden -> 404 Response
     */
    public function testStartReturns404WhenGameNotFound(): void
    {
        $gameId = 999;

        $request = Request::create(
            uri: "/api/game/{$gameId}/start",
            method: 'POST',
            parameters: [],
            cookies: [],
            files: [],
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['startScore' => 301])
        );

        $startGameRequest = $this->createStartGameRequest();

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('deserialize')->willReturn($startGameRequest);

        $gameRepository = $this->createMock(GameRepositoryInterface::class);
        $gameRepository->expects($this->once())
            ->method('find')
            ->with($gameId)
            ->willReturn(null);

        $gameStartService = $this->createMock(GameStartServiceInterface::class);
        $gameStartService->expects($this->never())->method('start');

        $this->container->method('has')->willReturn(false);

        $response = $this->controller->start(
            $gameId,
            $request,
            $gameRepository,
            $gameStartService,
            $serializer
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Game not found', $data['error']);
    }

    /**
     * Test: Start - InvalidArgumentException -> 400 Bad Request
     */
    public function testStartReturns400OnInvalidArgument(): void
    {
        $gameId = 42;
        $errorMessage = 'Game must have at least 2 players to start';

        $request = Request::create(
            uri: "/api/game/{$gameId}/start",
            method: 'POST',
            parameters: [],
            cookies: [],
            files: [],
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['startScore' => 301])
        );

        $startGameRequest = $this->createStartGameRequest();

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('deserialize')->willReturn($startGameRequest);

        $game = $this->createMock(Game::class);

        $gameRepository = $this->createMock(GameRepositoryInterface::class);
        $gameRepository->method('find')->willReturn($game);

        $gameStartService = $this->createMock(GameStartServiceInterface::class);
        $gameStartService->expects($this->once())
            ->method('start')
            ->with($game, $startGameRequest)
            ->willThrowException(new InvalidArgumentException($errorMessage));

        $this->container->method('has')->willReturn(false);

        $response = $this->controller->start(
            $gameId,
            $request,
            $gameRepository,
            $gameStartService,
            $serializer
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals($errorMessage, $data['error']);
    }
}
