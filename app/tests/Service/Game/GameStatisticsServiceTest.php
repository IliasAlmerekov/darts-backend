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
}
