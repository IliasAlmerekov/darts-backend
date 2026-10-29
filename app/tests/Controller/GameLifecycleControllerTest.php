<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GameLifecycleController;
use App\Dto\GameSummaryFinishedPlayerDto;
use App\Dto\GameSummaryResponseDto;
use App\Dto\GameSummaryWinnerDto;
use App\Dto\StartGameRequest;
use App\Dto\GameResponseDto;
use App\Dto\PlayerResponseDto;
use App\Dto\ThrowResponseDto;
use App\Entity\Game;
use App\EventSubscriber\ApiViewSubscriber;
use App\Exception\Game\GameMustHaveValidPlayerCountException;
use App\Service\Game\GameFinishServiceInterface;
use App\Service\Game\GameReopenServiceInterface;
use App\Service\Game\GameStateViewBuilderInterface;
use App\Service\Game\GameStartServiceInterface;
use App\Service\Security\GameAccessServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
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
        $game->method('getGameId')->willReturn(7);
        $dto = new StartGameRequest();
        $startService = $this->createMock(GameStartServiceInterface::class);
        $startService->expects($this->once())->method('start')->with($game, $dto)->willReturn($game);
        $gameStateViewBuilder = $this->createMock(GameStateViewBuilderInterface::class);
        $gameStateViewBuilder->expects($this->once())
            ->method('buildStateVersion')
            ->with($game)
            ->willReturn('state-v1');
        $gameDto = $this->dummyGameDto();
        $gameStateViewBuilder->expects($this->once())->method('createGameDto')->with($game)->willReturn($gameDto);
        $request = new Request();

        $response = $this->controller->start($game, $startService, $gameStateViewBuilder, $request, $dto);

        $this->assertSame($gameDto, $response);
        $this->assertSame([
            'ETag' => '"state-v1"',
            'Cache-Control' => 'private, no-cache',
            'X-Game-State-Version' => 'state-v1',
        ], $request->attributes->get(ApiViewSubscriber::RESPONSE_HEADERS_REQUEST_ATTRIBUTE));
    }

    public function testStartReturnsBadRequestOnError(): void
    {
        $game = $this->createMock(Game::class);
        $game->method('getGameId')->willReturn(7);
        $dto = new StartGameRequest();
        $startService = $this->createMock(GameStartServiceInterface::class);
        $startService->method('start')->willThrowException(new GameMustHaveValidPlayerCountException());
        $gameStateViewBuilder = $this->createMock(GameStateViewBuilderInterface::class);
        $request = new Request();

        $this->expectException(GameMustHaveValidPlayerCountException::class);
        $this->controller->start($game, $startService, $gameStateViewBuilder, $request, $dto);
    }

    public function testStartRespondsWithTheLockedGameTheServiceReturns(): void
    {
        $routeGame = $this->createMock(Game::class);
        $lockedGame = $this->createMock(Game::class);
        $startService = $this->createMock(GameStartServiceInterface::class);
        $startService->expects($this->once())->method('start')->with($routeGame)->willReturn($lockedGame);
        $gameStateViewBuilder = $this->createMock(GameStateViewBuilderInterface::class);
        $gameStateViewBuilder->expects($this->once())->method('buildStateVersion')->with($lockedGame)->willReturn('state-v2');
        $gameDto = $this->dummyGameDto();
        $gameStateViewBuilder->expects($this->once())->method('createGameDto')->with($lockedGame)->willReturn($gameDto);

        $response = $this->controller->start($routeGame, $startService, $gameStateViewBuilder, new Request(), new StartGameRequest());

        $this->assertSame($gameDto, $response);
    }

    public function testReopenRespondsWithTheLockedGameTheServiceReturns(): void
    {
        $routeGame = $this->createMock(Game::class);
        $lockedGame = $this->createMock(Game::class);
        $reopenService = $this->createMock(GameReopenServiceInterface::class);
        $reopenService->expects($this->once())->method('reopen')->with($routeGame)->willReturn($lockedGame);
        $gameStateViewBuilder = $this->createMock(GameStateViewBuilderInterface::class);
        $gameDto = $this->dummyGameDto();
        $gameStateViewBuilder->expects($this->once())->method('createGameDto')->with($lockedGame)->willReturn($gameDto);

        $this->assertSame($gameDto, $this->controller->reopen($routeGame, $reopenService, $gameStateViewBuilder));
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
        $game->method('getGameId')->willReturn(7);
        $reopenService = $this->createMock(GameReopenServiceInterface::class);
        $reopenService->expects($this->once())->method('reopen')->with($game)->willReturn($game);

        $gameStateViewBuilder = $this->createMock(GameStateViewBuilderInterface::class);
        $gameStateViewBuilder->method('createGameDto')->willReturn($this->dummyGameDto());

        $response = $this->controller->reopen($game, $reopenService, $gameStateViewBuilder);

        $this->assertInstanceOf(GameResponseDto::class, $response);
    }

    public function testGetGameStateReturnsJsonWithVersionHeaders(): void
    {
        $game = $this->createMock(Game::class);
        $gameAccessService = $this->createMock(GameAccessServiceInterface::class);
        $gameAccessService->expects($this->once())
            ->method('assertAdmin');
        $gameStateViewBuilder = $this->createMock(GameStateViewBuilderInterface::class);
        $gameStateViewBuilder->expects($this->once())
            ->method('buildStateVersion')
            ->with($game)
            ->willReturn('state-v1');
        $gameStateViewBuilder->expects($this->once())
            ->method('createGameDto')
            ->with($game)
            ->willReturn($this->dummyGameDto());

        $request = new Request();
        $response = $this->controller->getGameState($game, $gameAccessService, $gameStateViewBuilder, $request);

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
            ->method('assertAdmin');

        $gameStateViewBuilder = $this->createMock(GameStateViewBuilderInterface::class);
        $gameStateViewBuilder->expects($this->once())
            ->method('buildStateVersion')
            ->with($game)
            ->willReturn('state-contract');
        $gameStateViewBuilder->expects($this->once())
            ->method('createGameDto')
            ->with($game)
            ->willReturn($this->complexGameDto());

        $response = $this->controller->getGameState($game, $gameAccessService, $gameStateViewBuilder, new Request());

        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('state-contract', $response->headers->get('X-Game-State-Version'));
        $this->assertSame([
            'type' => 'full-state',
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
                    'placement' => null,
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
                                    'value' => 20,
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
                    'placement' => 1,
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
            ->method('assertAdmin');
        $gameStateViewBuilder = $this->createMock(GameStateViewBuilderInterface::class);
        $gameStateViewBuilder->expects($this->once())
            ->method('buildStateVersion')
            ->with($game)
            ->willReturn('state-v1');
        $gameStateViewBuilder->expects($this->never())
            ->method('createGameDto');

        $request = new Request();
        $response = $this->controller->getGameState($game, $gameAccessService, $gameStateViewBuilder, $request, 'state-v1');

        $this->assertSame(Response::HTTP_NOT_MODIFIED, $response->getStatusCode());
        $this->assertSame('state-v1', $response->headers->get('X-Game-State-Version'));
    }

    public function testGetGameStateReturnsNotModifiedWhenIfNoneMatchMatches(): void
    {
        $game = $this->createMock(Game::class);
        $gameAccessService = $this->createMock(GameAccessServiceInterface::class);
        $gameAccessService->expects($this->once())
            ->method('assertAdmin');
        $gameStateViewBuilder = $this->createMock(GameStateViewBuilderInterface::class);
        $gameStateViewBuilder->expects($this->once())
            ->method('buildStateVersion')
            ->with($game)
            ->willReturn('state-v1');
        $gameStateViewBuilder->expects($this->never())
            ->method('createGameDto');

        $request = new Request();
        $request->headers->set('If-None-Match', '"state-v1"');

        $response = $this->controller->getGameState($game, $gameAccessService, $gameStateViewBuilder, $request);

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
                    placement: null,
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
                                    value: 20,
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
                    placement: 1,
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
                    position: 2,
                    placement: 1,
                    roundsPlayed: 5,
                    roundAverage: 60.0,
                ),
            ],
        );
    }
}
