<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GameStatsController;
use App\Entity\Game;
use App\Exception\Game\GameNotFoundException;
use App\Repository\GameRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Game\GameFinishServiceInterface;
use App\Service\Game\GameStatisticsServiceInterface;
use App\Dto\PlayerStatsDto;
use App\Dto\GameOverviewResponseDto;
use App\Dto\PlayerStatsResponseDto;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

#[AllowMockObjectsWithoutExpectations]
final class GameStatsControllerTest extends TestCase
{
    private GameStatsController $controller;
    private ContainerInterface&MockObject $container;

    protected function setUp(): void
    {
        $this->controller = new GameStatsController();
        $this->container = $this->createMock(ContainerInterface::class);
        $this->controller->setContainer($this->container);
    }

    public function testGamesOverviewReturnsList(): void
    {
        $game = $this->createMock(Game::class);
        $gameRepo = $this->createMock(GameRepositoryInterface::class);
        $gameRepo->method('findFinished')->willReturn([$game]);
        $gameRepo->method('countFinishedGames')->willReturn(1);

        $finishService = $this->createMock(GameFinishServiceInterface::class);
        $finishService->method('getGameStats')->willReturn([
            'gameId' => 1,
            'date' => new DateTimeImmutable(),
            'finishedAt' => new DateTimeImmutable(),
            'finishedPlayers' => [],
            'winner' => ['username' => 'u', 'id' => 1],
            'winnerRoundsPlayed' => 3,
        ]);

        $response = $this->controller->gamesOverview($gameRepo, $finishService, 10, 0);

        $this->assertInstanceOf(GameOverviewResponseDto::class, $response);
    }

    public function testGameDetailsReturnsStats(): void
    {
        $game = $this->createMock(Game::class);
        $gameRepo = $this->createMock(GameRepositoryInterface::class);
        $gameRepo->method('find')->with(42)->willReturn($game);

        $finishService = $this->createMock(GameFinishServiceInterface::class);
        $finishService->method('getGameStats')->with($game)->willReturn([
            'gameId' => 42,
            'date' => new DateTimeImmutable(),
            'finishedAt' => new DateTimeImmutable(),
            'winner' => ['username' => 'winner', 'id' => 10],
            'winnerRoundsPlayed' => 8,
            'winnerRoundAverage' => 45.2,
            'finishedPlayers' => [],
        ]);

        $response = $this->controller->gameDetails(42, $gameRepo, $finishService);

        $this->assertIsArray($response);
        $this->assertSame(42, $response['gameId']);
    }

    public function testGameDetailsThrowsWhenGameMissing(): void
    {
        $gameRepo = $this->createMock(GameRepositoryInterface::class);
        $gameRepo->method('find')->with(999)->willReturn(null);

        $finishService = $this->createMock(GameFinishServiceInterface::class);
        $finishService->expects($this->never())->method('getGameStats');

        $this->expectException(GameNotFoundException::class);
        $this->controller->gameDetails(999, $gameRepo, $finishService);
    }

    public function testPlayerStatsReturnsData(): void
    {
        $statsService = $this->createMock(GameStatisticsServiceInterface::class);
        $statsService->method('getPlayerStats')->willReturn([new PlayerStatsDto(1, 'p', 1, 50.0)]);

        $throwsRepo = $this->createMock(RoundThrowsRepositoryInterface::class);
        $throwsRepo->method('countPlayersWithFinishedRounds')->willReturn(1);

        $response = $this->controller->playerStats($statsService, $throwsRepo, 20, 0, 'average:desc');

        $this->assertInstanceOf(PlayerStatsResponseDto::class, $response);
    }
}
