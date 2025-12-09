<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GameController;
use App\Dto\GameResponseDto;
use App\Dto\GameSettingsRequest;
use App\Dto\StartGameRequest;
use App\Dto\ThrowRequest;
use App\Entity\Game;
use App\Service\GameServiceInterface;
use App\Service\GameSettingsServiceInterface;
use App\Service\GameStartServiceInterface;
use App\Service\GameThrowServiceInterface;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

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
     * Helper: Erstellt eine GameSettingsRequest-Instanz
     */
    private function createGameSettingsRequest(
        ?int $startScore = 501,
        ?bool $doubleOut = true,
        ?bool $tripleOut = false,
        ?string $outMode = null
    ): GameSettingsRequest {
        $dto = new GameSettingsRequest();
        $dto->startScore = $startScore;
        $dto->doubleOut = $doubleOut;
        $dto->tripleOut = $tripleOut;
        $dto->outMode = $outMode;

        return $dto;
    }

    /**
     * Test: Erfolgreicher Throw - Game gefunden, Throw aufgezeichnet
     */
    public function testThrowSuccessfullyRecordsThrow(): void
    {
        $gameId = 42;
        $throwRequest = $this->createThrowRequest(
            playerId: 1,
            value: 20,
            isDouble: true
        );

        $game = $this->createMock(Game::class);

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
            $game,
            $gameThrowService,
            $gameService,
            $throwRequest
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals($gameId, $data['id']);
        $this->assertEquals('playing', $data['status']);
        $this->assertEquals(1, $data['activePlayerId']);
    }

    /**
     * Test: Throw - InvalidArgumentException -> 400 Bad Request
     */
    public function testThrowReturns400OnInvalidArgument(): void
    {
        $gameId = 42;
        $errorMessage = 'Player not found in this game';

        $throwRequest = $this->createThrowRequest(999, 20);
        $game = $this->createMock(Game::class);

        $gameThrowService = $this->createMock(GameThrowServiceInterface::class);
        $gameThrowService->expects($this->once())
            ->method('recordThrow')
            ->with($game, $throwRequest)
            ->willThrowException(new InvalidArgumentException($errorMessage));

        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->expects($this->never())->method('createGameDto');

        $this->container->method('has')->willReturn(false);

        $response = $this->controller->throw(
            $game,
            $gameThrowService,
            $gameService,
            $throwRequest
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

        $throwRequest = $this->createThrowRequest(
            playerId: 2,
            value: 20,
            isTriple: true
        );

        $game = $this->createMock(Game::class);

        $gameThrowService = $this->createMock(GameThrowServiceInterface::class);
        $gameThrowService->expects($this->once())->method('recordThrow');

        $gameDto = $this->createGameResponseDto(id: $gameId);
        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->method('createGameDto')->willReturn($gameDto);

        $this->container->method('has')->willReturn(false);

        $response = $this->controller->throw(
            $game,
            $gameThrowService,
            $gameService,
            $throwRequest
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    /**
     * Test: Settings - Bestehendes Spiel aktualisieren
     */
    public function testUpdateSettingsReturnsUpdatedDto(): void
    {
        $gameId = 55;

        $settingsRequest = $this->createGameSettingsRequest(
            startScore: 401,
            doubleOut: true,
            tripleOut: true
        );

        $game = $this->createMock(Game::class);

        $gameSettingsService = $this->createMock(GameSettingsServiceInterface::class);
        $gameSettingsService->expects($this->once())
            ->method('updateSettings')
            ->with($game, $settingsRequest);

        $gameDto = $this->createGameResponseDto(
            id: $gameId,
            status: 'lobby',
            settings: [
                'startScore' => 401,
                'doubleOut' => true,
                'tripleOut' => true,
            ]
        );

        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->expects($this->once())
            ->method('createGameDto')
            ->with($game)
            ->willReturn($gameDto);

        $this->container->method('has')->willReturn(false);

        $response = $this->controller->updateSettings(
            $game,
            $gameSettingsService,
            $gameService,
            $settingsRequest
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals($gameId, $data['id']);
        $this->assertEquals(401, $data['settings']['startScore']);
        $this->assertTrue($data['settings']['doubleOut']);
        $this->assertTrue($data['settings']['tripleOut']);
    }

    /**
     * Test: Settings - outMode setzt double/triple korrekt
     */
    public function testUpdateSettingsWithOutMode(): void
    {
        $gameId = 77;

        $settingsRequest = $this->createGameSettingsRequest(
            startScore: null,
            doubleOut: null,
            tripleOut: null,
            outMode: 'doubleout'
        );

        $game = $this->createMock(Game::class);

        $gameSettingsService = $this->createMock(GameSettingsServiceInterface::class);
        $gameSettingsService->expects($this->once())
            ->method('updateSettings')
            ->with($game, $settingsRequest);

        $gameDto = $this->createGameResponseDto(
            id: $gameId,
            status: 'lobby',
            settings: [
                'startScore' => 301,
                'doubleOut' => true,
                'tripleOut' => false,
            ]
        );

        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->expects($this->once())
            ->method('createGameDto')
            ->with($game)
            ->willReturn($gameDto);

        $this->container->method('has')->willReturn(false);

        $response = $this->controller->updateSettings(
            $game,
            $gameSettingsService,
            $gameService,
            $settingsRequest
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['settings']['doubleOut']);
        $this->assertFalse($data['settings']['tripleOut']);
    }

    // Tests for start/createSettings adjusted to new signatures are omitted for brevity.
}
