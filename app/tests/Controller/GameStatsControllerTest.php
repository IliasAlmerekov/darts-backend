<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GameStatsController;
use App\Dto\GameOverviewResponseDto;
use App\Dto\GameSummaryFinishedPlayerDto;
use App\Dto\GameSummaryResponseDto;
use App\Dto\GameSummaryWinnerDto;
use App\Entity\Game;
use App\Exception\Game\GameNotFoundException;
use App\Repository\GameRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Game\GameFinishServiceInterface;
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
                    'date' => '2026-03-10T00:00:00+00:00',
                    'finishedAt' => '2026-03-10T12:34:56+00:00',
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
        $this->assertSame('winner', $response->items[0]->winnerName);
        $this->assertSame(3, $response->items[0]->winnerRounds);
    }

    public function testGameDetailsReturnsStats(): void
    {
        $game = $this->createMock(Game::class);
        $gameRepo = $this->createMock(GameRepositoryInterface::class);
        $gameRepo->method('find')->with(42)->willReturn($game);

        $finishService = $this->createMock(GameFinishServiceInterface::class);
        $finishService->method('getGameStats')->with($game)->willReturn(
            $this->createGameSummaryDto(gameId: 42, winnerId: 10, winnerName: 'winner', winnerRoundsPlayed: 8, winnerRoundAverage: 45.2)
        );

        $response = $this->controller->gameDetails(42, $gameRepo, $finishService);

        $this->assertInstanceOf(GameSummaryResponseDto::class, $response);
        $this->assertSame(42, $response->gameId);
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

    private function createGameSummaryDto(
        int $gameId,
        int $winnerId = 1,
        ?string $winnerName = 'u',
        int $winnerRoundsPlayed = 3,
        float $winnerRoundAverage = 60.0,
    ): GameSummaryResponseDto {
        return new GameSummaryResponseDto(
            gameId: $gameId,
            finishedAt: (new DateTimeImmutable())->format(DATE_ATOM),
            winner: new GameSummaryWinnerDto($winnerId, $winnerName),
            winnerRoundsPlayed: $winnerRoundsPlayed,
            winnerRoundAverage: $winnerRoundAverage,
            finishedPlayers: [
                new GameSummaryFinishedPlayerDto(
                    playerId: $winnerId,
                    username: $winnerName,
                    position: 1,
                    roundsPlayed: $winnerRoundsPlayed,
                    roundAverage: $winnerRoundAverage,
                ),
            ],
        );
    }
}
