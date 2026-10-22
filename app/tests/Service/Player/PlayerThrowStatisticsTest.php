<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service\Player;

use App\Dto\PlayerProfileDoubleDto;
use App\Dto\PlayerProfileFinishDto;
use App\Dto\PlayerProfileMostHitSegmentDto;
use App\Service\Player\PlayerThrowStatistics;
use PHPUnit\Framework\TestCase;

/**
 * The tie-breaks of the Profile statistics. Each game here holds one turn of the player.
 */
final class PlayerThrowStatisticsTest extends TestCase
{
    private int $nextGameId = 1;

    public function testFavoriteDoublesKeepTheTopThreeByHitsThenHitRateThenHigherDouble(): void
    {
        $throws = [
            ...$this->turn(32, 'double', [[32, 'D', 0]]),
            ...$this->turn(32, 'double', [[32, 'D', 0]]),
            ...$this->turn(20, 'double', [[20, 'D', 0]]),
            ...$this->turn(36, 'double', [[36, 'D', 0]]),
            ...$this->turn(10, 'double', [[0, 'S', 10], [10, 'D', 0]]),
        ];

        self::assertEquals([
            new PlayerProfileDoubleDto('D16', 2, 2, 100.0),
            new PlayerProfileDoubleDto('D18', 1, 1, 100.0),
            new PlayerProfileDoubleDto('D10', 1, 1, 100.0),
        ], PlayerThrowStatistics::build(5, 20.0, $throws)->favoriteDoubles);
    }

    public function testHighestFinishTieGoesToFewerDartsThenToTheEarlierFinish(): void
    {
        $throws = [
            ...$this->turn(36, 'single', [[20, 'S', 16], [16, 'D', 0]]),
            ...$this->turn(36, 'double', [[36, 'D', 0]]),
            ...$this->turn(36, 'single', [[36, 'T', 0]]),
        ];

        self::assertEquals(
            new PlayerProfileFinishDto(36, 1, ['D18']),
            PlayerThrowStatistics::build(3, 20.0, $throws)->highestFinish
        );
    }

    public function testMostHitSegmentTieGoesToTheHigherSegmentAndMissesCountInTheShare(): void
    {
        $throws = $this->turn(301, 'single', [[25, 'S', 276], [20, 'S', 256], [0, 'S', 256]]);

        self::assertEquals(
            new PlayerProfileMostHitSegmentDto('BULL', 1, 33.3),
            PlayerThrowStatistics::build(1, 45.0, $throws)->mostHitSegment
        );
    }

    /**
     * One turn in a new finished game the player won.
     *
     * @param 'single'|'double'          $outRule
     * @param list<array{int, string, int}> $darts value as stored, ring S, D or T, score after the dart
     *
     * @return list<array{gameId:int, roundId:int, value:int, isDouble:bool, isTriple:bool, isBust:bool, score:int, startScore:int, doubleOut:bool, tripleOut:bool, placement:int|null}>
     */
    private function turn(int $startScore, string $outRule, array $darts): array
    {
        $gameId = $this->nextGameId++;

        return array_map(static fn(array $dart): array => [
            'gameId' => $gameId,
            'roundId' => $gameId,
            'value' => $dart[0],
            'isDouble' => 'D' === $dart[1],
            'isTriple' => 'T' === $dart[1],
            'isBust' => false,
            'score' => $dart[2],
            'startScore' => $startScore,
            'doubleOut' => 'double' === $outRule,
            'tripleOut' => false,
            'placement' => 1,
        ], $darts);
    }
}
