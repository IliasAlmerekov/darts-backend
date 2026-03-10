<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GameLifecycleController;
use App\Dto\GameSummaryFinishedPlayerDto;
use App\Dto\GameSummaryResponseDto;
use App\Dto\GameSummaryWinnerDto;
use App\Dto\GameSettingsResponseDto;
use App\Dto\GameSettingsRequest;
use App\Dto\StartGameRequest;
use App\Dto\GameResponseDto;
use App\Dto\PlayerResponseDto;
use App\Dto\ThrowResponseDto;
use App\Entity\Game;
use App\Enum\GameStatus;
use App\Exception\Game\GameIdMissingException;
use App\Exception\Game\GameMustHaveValidPlayerCountException;
use App\Exception\Game\NoSettingsProvidedException;
use App\Service\Game\GameFinishServiceInterface;
use App\Service\Game\GameReopenServiceInterface;
use App\Service\Game\GameRoomServiceInterface;
use App\Service\Game\GameServiceInterface;
use App\Service\Game\GameSettingsServiceInterface;
use App\Service\Game\GameStartServiceInterface;
use App\Service\Game\RematchStartServiceInterface;
use App\Service\Security\GameAccessServiceInterface;
use OpenApi\Attributes as OA;
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

    public function testCreateAndStartRematchReturnsStartedGame(): void
    {
        $gameId = 42;
        $game = $this->createMock(Game::class);
        $dto = new StartGameRequest();
        $rematchStartService = $this->createMock(RematchStartServiceInterface::class);
        $rematchStartService->expects($this->once())
            ->method('createAndStart')
            ->with($gameId, $dto)
            ->willReturn($game);

        $response = $this->controller->createAndStartRematch($gameId, $rematchStartService, $dto);

        $this->assertSame($game, $response);
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

    public function testCreateSettingsIsDocumentedAsDeprecatedLegacyFlow(): void
    {
        $operation = (new \ReflectionMethod(GameLifecycleController::class, 'createSettings'))
            ->getAttributes(OA\Post::class)[0]
            ->newInstance();

        $this->assertTrue($operation->deprecated);
        $this->assertStringContainsString('Deprecated.', (string) $operation->description);
        $this->assertStringContainsString('/api/room/create', (string) $operation->description);
        $this->assertStringContainsString('/api/game/{gameId}/settings', (string) $operation->description);
    }

    public function testUpdateSettingsReturnsBadRequestOnError(): void
    {
        $dto = new GameSettingsRequest();
        $game = $this->createMock(Game::class);
        $settingsService = $this->createMock(GameSettingsServiceInterface::class);
        $settingsService->method('updateSettings')->willThrowException(new NoSettingsProvidedException());

        $this->expectException(NoSettingsProvidedException::class);
        $this->controller->updateSettings($game, $settingsService, $dto);
    }

    public function testUpdateSettingsReturnsLightweightSettingsPayload(): void
    {
        $dto = new GameSettingsRequest();
        $dto->startScore = 501;
        $dto->doubleOut = true;

        $game = $this->createMock(Game::class);
        $game->expects($this->once())
            ->method('getGameId')
            ->willReturn(55);
        $game->expects($this->once())
            ->method('getStartScore')
            ->willReturn(501);
        $game->expects($this->once())
            ->method('isDoubleOut')
            ->willReturn(true);
        $game->expects($this->once())
            ->method('isTripleOut')
            ->willReturn(false);
        $game->expects($this->once())
            ->method('getStatus')
            ->willReturn(GameStatus::Lobby);

        $settingsService = $this->createMock(GameSettingsServiceInterface::class);
        $settingsService->expects($this->once())
            ->method('updateSettings')
            ->with($game, $dto);

        $response = $this->controller->updateSettings($game, $settingsService, $dto);

        $this->assertInstanceOf(GameSettingsResponseDto::class, $response);
        $this->assertSame(55, $response->gameId);
        $this->assertSame(501, $response->startScore);
        $this->assertTrue($response->doubleOut);
        $this->assertFalse($response->tripleOut);
        $this->assertSame('lobby', $response->status);
    }

    public function testGetSettingsReturnsLightweightSettingsPayload(): void
    {
        $game = $this->createMock(Game::class);
        $game->expects($this->once())
            ->method('getGameId')
            ->willReturn(55);
        $game->expects($this->once())
            ->method('getStartScore')
            ->willReturn(301);
        $game->expects($this->once())
            ->method('isDoubleOut')
            ->willReturn(true);
        $game->expects($this->once())
            ->method('isTripleOut')
            ->willReturn(false);
        $game->expects($this->once())
            ->method('getStatus')
            ->willReturn(GameStatus::Started);

        $gameAccessService = $this->createMock(GameAccessServiceInterface::class);
        $gameAccessService->expects($this->once())
            ->method('assertPlayerInGameOrAdmin')
            ->with($game);

        $response = $this->controller->getSettings($game, $gameAccessService);

        $this->assertInstanceOf(GameSettingsResponseDto::class, $response);
        $this->assertSame(55, $response->gameId);
        $this->assertSame(301, $response->startScore);
        $this->assertTrue($response->doubleOut);
        $this->assertFalse($response->tripleOut);
        $this->assertSame('started', $response->status);
    }

    public function testCompactSettingsOperationsAreDocumentedAsPreferredFlow(): void
    {
        $updateOperation = (new \ReflectionMethod(GameLifecycleController::class, 'updateSettings'))
            ->getAttributes(OA\Patch::class)[0]
            ->newInstance();
        $readOperation = (new \ReflectionMethod(GameLifecycleController::class, 'getSettings'))
            ->getAttributes(OA\Get::class)[0]
            ->newInstance();

        $this->assertStringContainsString('Bevorzugter Settings-Flow', (string) $updateOperation->description);
        $this->assertStringContainsString('GameSettingsResponseDto', (string) $updateOperation->description);
        $this->assertStringContainsString('Bevorzugter Read-Flow', (string) $readOperation->description);
        $this->assertStringContainsString('vollständigen Spielzustand', (string) $readOperation->description);
    }

    public function testGetSettingsThrowsWhenGameIdIsMissing(): void
    {
        $game = $this->createMock(Game::class);
        $game->expects($this->once())
            ->method('getGameId')
            ->willReturn(null);

        $gameAccessService = $this->createMock(GameAccessServiceInterface::class);
        $gameAccessService->expects($this->once())
            ->method('assertPlayerInGameOrAdmin')
            ->with($game);

        $this->expectException(GameIdMissingException::class);
        $this->controller->getSettings($game, $gameAccessService);
    }

    public function testFinishReturnsUnifiedSummaryPayload(): void
    {
        $game = $this->createMock(Game::class);
        $summary = $this->createGameSummaryDto();
        $finishService = $this->createMock(GameFinishServiceInterface::class);
        $finishService->method('finishGame')->with($game)->willReturn($summary);

        $response = $this->controller->finish($game, $finishService);

        $this->assertSame($summary, $response);
        $this->assertInstanceOf(GameSummaryResponseDto::class, $response);
    }

    public function testFinishedReturnsUnifiedSummaryPayload(): void
    {
        $game = $this->createMock(Game::class);
        $summary = $this->createGameSummaryDto();
        $finishService = $this->createMock(GameFinishServiceInterface::class);
        $finishService->method('getGameSummary')->with($game)->willReturn($summary);

        $response = $this->controller->finished($game, $finishService);

        $this->assertSame($summary, $response);
        $this->assertInstanceOf(GameSummaryResponseDto::class, $response);
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

    public function testGetGameStateSerializesFullGameStateContract(): void
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
            ->willReturn('state-contract');
        $gameService->expects($this->once())
            ->method('createGameDto')
            ->with($game)
            ->willReturn($this->complexGameDto());

        $response = $this->controller->getGameState($game, $gameAccessService, $gameService, new Request());

        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('state-contract', $response->headers->get('X-Game-State-Version'));
        $this->assertSame([
            'id' => 55,
            'status' => 'started',
            'currentRound' => 9,
            'activePlayerId' => 7,
            'currentThrowCount' => 1,
            'players' => [
                [
                    'id' => 7,
                    'name' => 'Alpha',
                    'score' => 81,
                    'isActive' => true,
                    'isBust' => false,
                    'position' => 1,
                    'throwsInCurrentRound' => 1,
                    'currentRoundThrows' => [
                        [
                            'value' => 60,
                            'isDouble' => false,
                            'isTriple' => true,
                            'isBust' => false,
                        ],
                    ],
                    'roundHistory' => [
                        [
                            'round' => 8,
                            'throws' => [
                                [
                                    'value' => 45,
                                    'isDouble' => false,
                                    'isTriple' => false,
                                    'isBust' => false,
                                ],
                            ],
                        ],
                        [
                            'round' => 9,
                            'throws' => [
                                [
                                    'value' => 60,
                                    'isDouble' => false,
                                    'isTriple' => true,
                                    'isBust' => false,
                                ],
                            ],
                        ],
                    ],
                    'isGuest' => false,
                ],
                [
                    'id' => 8,
                    'name' => 'Beta',
                    'score' => 0,
                    'isActive' => false,
                    'isBust' => false,
                    'position' => 2,
                    'throwsInCurrentRound' => 0,
                    'currentRoundThrows' => [],
                    'roundHistory' => [],
                    'isGuest' => true,
                ],
            ],
            'winnerId' => 8,
            'settings' => [
                'startScore' => 301,
                'doubleOut' => true,
                'tripleOut' => false,
            ],
        ], $payload);
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

    private function complexGameDto(): GameResponseDto
    {
        return new GameResponseDto(
            id: 55,
            status: 'started',
            currentRound: 9,
            activePlayerId: 7,
            currentThrowCount: 1,
            players: [
                new PlayerResponseDto(
                    id: 7,
                    name: 'Alpha',
                    score: 81,
                    isActive: true,
                    isBust: false,
                    position: 1,
                    throwsInCurrentRound: 1,
                    currentRoundThrows: [
                        new ThrowResponseDto(
                            value: 60,
                            isDouble: false,
                            isTriple: true,
                            isBust: false,
                        ),
                    ],
                    roundHistory: [
                        [
                            'round' => 8,
                            'throws' => [
                                new ThrowResponseDto(
                                    value: 45,
                                    isDouble: false,
                                    isTriple: false,
                                    isBust: false,
                                ),
                            ],
                        ],
                        [
                            'round' => 9,
                            'throws' => [
                                new ThrowResponseDto(
                                    value: 60,
                                    isDouble: false,
                                    isTriple: true,
                                    isBust: false,
                                ),
                            ],
                        ],
                    ],
                    isGuest: false,
                ),
                new PlayerResponseDto(
                    id: 8,
                    name: 'Beta',
                    score: 0,
                    isActive: false,
                    isBust: false,
                    position: 2,
                    throwsInCurrentRound: 0,
                    currentRoundThrows: [],
                    roundHistory: [],
                    isGuest: true,
                ),
            ],
            winnerId: 8,
            settings: [
                'startScore' => 301,
                'doubleOut' => true,
                'tripleOut' => false,
            ],
        );
    }

    private function createGameSummaryDto(): GameSummaryResponseDto
    {
        return new GameSummaryResponseDto(
            gameId: 55,
            finishedAt: '2026-03-10T12:00:00+00:00',
            winner: new GameSummaryWinnerDto(1, 'player'),
            winnerRoundsPlayed: 5,
            winnerRoundAverage: 60.0,
            finishedPlayers: [
                new GameSummaryFinishedPlayerDto(
                    playerId: 1,
                    username: 'player',
                    position: 1,
                    roundsPlayed: 5,
                    roundAverage: 60.0,
                ),
            ],
        );
    }
}
