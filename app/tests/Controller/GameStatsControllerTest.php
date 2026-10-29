<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GameStatsController;
use App\Dto\GameOverviewResponseDto;
use App\Repository\GameRepositoryInterface;
use App\Repository\PlayerStatisticsRepositoryInterface;
use App\Service\Game\GameStatisticsServiceInterface;
use App\Dto\PlayerStatsDto;
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
        $gameRepo = $this->createMock(GameRepositoryInterface::class);
        $gameRepo->expects($this->once())
            ->method('findFinishedOverview')
            ->with(10, 0)
            ->willReturn([
                [
                    'id' => 1,
                    'date' => new DateTimeImmutable('2026-03-10 00:00:00+00:00'),
                    'finishedAt' => new DateTimeImmutable('2026-03-10 12:34:56+00:00'),
                    'playersCount' => 2,
                    'winnerName' => 'winner',
                    'winnerId' => 7,
                    'winnerRounds' => 3,
                ],
            ]);
        $gameRepo->expects($this->never())->method('findFinished');
        $gameRepo->method('countFinishedGames')->willReturn(1);

        $response = $this->controller->gamesOverview($gameRepo, 10, 0);

        $this->assertInstanceOf(GameOverviewResponseDto::class, $response);
        $this->assertCount(1, $response->items);
        $this->assertSame(1, $response->items[0]->id);
        $this->assertSame('2026-03-10T00:00:00+00:00', $response->items[0]->date);
        $this->assertSame('2026-03-10T12:34:56+00:00', $response->items[0]->finishedAt);
        $this->assertSame('winner', $response->items[0]->winnerName);
        $this->assertSame(3, $response->items[0]->winnerRounds);
    }

    public function testPlayerStatsReturnsData(): void
    {
        $statsService = $this->createMock(GameStatisticsServiceInterface::class);
        $statsService->method('getPlayerStats')->willReturn([new PlayerStatsDto(1, 'p', 1, 50.0)]);

        $throwsRepo = $this->createMock(PlayerStatisticsRepositoryInterface::class);
        $throwsRepo->method('countPlayersWithStatistics')->willReturn(1);

        $response = $this->controller->playerStats($statsService, $throwsRepo, 20, 0, 'average:desc');

        $this->assertInstanceOf(PlayerStatsResponseDto::class, $response);
    }
}
