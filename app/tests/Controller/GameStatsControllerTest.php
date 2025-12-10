<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GameStatsController;
use App\Entity\Game;
use App\Repository\GameRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Game\GameFinishServiceInterface;
use App\Service\Game\GameStatisticsServiceInterface;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

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

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testPlayerStatsReturnsData(): void
    {
        $statsService = $this->createMock(GameStatisticsServiceInterface::class);
        $statsService->method('getPlayerStats')->willReturn([['id' => 1, 'average' => 50]]);

        $throwsRepo = $this->createMock(RoundThrowsRepositoryInterface::class);
        $throwsRepo->method('countPlayersWithFinishedRounds')->willReturn(1);

        $response = $this->controller->playerStats($statsService, $throwsRepo, 20, 0, 'average:desc');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }
}
