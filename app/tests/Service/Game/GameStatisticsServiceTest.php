<?php

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Dto\PlayerStatsDto;
use App\Service\Game\GameStatisticsService;
use App\Repository\RoundThrowsRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GameStatisticsServiceTest extends TestCase
{
    private GameStatisticsService $service;
    private RoundThrowsRepositoryInterface&MockObject $roundThrowsRepository;

    protected function setUp(): void
    {
        $this->roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $this->service = new GameStatisticsService($this->roundThrowsRepository);
    }

    public function testGetPlayerStatsMapsRepositoryRowsToDto(): void
    {
        $this->roundThrowsRepository
            ->expects(self::once())
            ->method('getPlayerStatistics')
            ->with(10, 0, 'average', 'DESC')
            ->willReturn([
                [
                    'playerId' => 1,
                    'username' => 'alice',
                    'gamesPlayed' => 5,
                    'totalValue' => '150.0',
                    'roundsFinished' => '10',
                ],
                [
                    'playerId' => 2,
                    'username' => 'bob',
                    'gamesPlayed' => 3,
                    'totalValue' => '60.0',
                    'roundsFinished' => '0', // защитный кейс: деление на 0
                ],
            ]);

        $result = $this->service->getPlayerStats(10, 0, 'average', 'DESC');

        self::assertCount(2, $result);
        self::assertContainsOnlyInstancesOf(PlayerStatsDto::class, $result);

        /** @var PlayerStatsDto $first */
        $first = $result[0];
        self::assertSame(1, $first->playerId);
        self::assertSame('alice', $first->name);
        self::assertSame(5, $first->gamesPlayed);
        self::assertSame(15.0, $first->scoreAverage);

        /** @var PlayerStatsDto $second */
        $second = $result[1];
        self::assertSame(2, $second->playerId);
        self::assertSame('bob', $second->name);
        self::assertSame(3, $second->gamesPlayed);
        self::assertSame(0.0, $second->scoreAverage);
    }

    public function testGetPlayerStatsMapsLegacyNumericRowsWithoutWarnings(): void
    {
        $this->roundThrowsRepository
            ->expects(self::once())
            ->method('getPlayerStatistics')
            ->with(10, 0, 'average', 'DESC')
            ->willReturn([
                [
                    0 => '7',
                    'username' => 'legacy-player',
                    2 => '4',
                    3 => '88.0',
                    4 => '8',
                ],
                [
                    0 => '8',
                    1 => 'precomputed-player',
                    2 => '6',
                    5 => '22.5',
                ],
            ]);

        $result = $this->service->getPlayerStats(10, 0, 'average', 'DESC');

        self::assertCount(2, $result);

        self::assertSame(7, $result[0]->playerId);
        self::assertSame('legacy-player', $result[0]->name);
        self::assertSame(4, $result[0]->gamesPlayed);
        self::assertSame(11.0, $result[0]->scoreAverage);

        self::assertSame(8, $result[1]->playerId);
        self::assertSame('precomputed-player', $result[1]->name);
        self::assertSame(6, $result[1]->gamesPlayed);
        self::assertSame(22.5, $result[1]->scoreAverage);
    }

    public function testGetPlayerStatsMapsLowercaseAndSnakeCaseAliases(): void
    {
        $this->roundThrowsRepository
            ->expects(self::once())
            ->method('getPlayerStatistics')
            ->with(10, 0, 'average', 'DESC')
            ->willReturn([
                [
                    'playerid' => '11',
                    'username' => 'postgres-lower',
                    'gamesplayed' => '7',
                    'totalvalue' => '140.0',
                    'roundsfinished' => '4',
                ],
                [
                    'player_id' => '12',
                    'username' => 'postgres-snake',
                    'games_played' => '8',
                    'score_average' => '37.5',
                ],
            ]);

        $result = $this->service->getPlayerStats(10, 0, 'average', 'DESC');

        self::assertCount(2, $result);
        self::assertSame(11, $result[0]->playerId);
        self::assertSame('postgres-lower', $result[0]->name);
        self::assertSame(7, $result[0]->gamesPlayed);
        self::assertSame(35.0, $result[0]->scoreAverage);

        self::assertSame(12, $result[1]->playerId);
        self::assertSame('postgres-snake', $result[1]->name);
        self::assertSame(8, $result[1]->gamesPlayed);
        self::assertSame(37.5, $result[1]->scoreAverage);
    }
}
