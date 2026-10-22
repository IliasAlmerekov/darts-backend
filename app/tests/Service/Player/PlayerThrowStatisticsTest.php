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

    /**
     * 1 of 300 and 1 of 301 both round to 0.3, but D16 has the better hit rate.
     */
    public function testFavoriteDoublesCompareTheExactHitRateNotTheRoundedOne(): void
    {
        $throws = [...$this->bustsThenCheckout(40, 300), ...$this->bustsThenCheckout(32, 299)];

        self::assertEquals([
            new PlayerProfileDoubleDto('D16', 1, 300, 0.3),
            new PlayerProfileDoubleDto('D20', 1, 301, 0.3),
        ], PlayerThrowStatistics::build(2, 20.0, $throws)->favoriteDoubles);
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
     * Under triple out only 3 to 60 divisible by 3 is checkable: 40 and 20 are not, 60 and 39 are.
     * A triple-out checkout is a Finish but never a favorite double.
     */
    public function testTripleOutCountsOnlyScoresOneTripleChecksOut(): void
    {
        $throws = [
            ...$this->turn(40, 'triple', [[1, 'S', 39], [39, 'T', 0]]),
            ...$this->turn(60, 'triple', [[20, 'S', 40], [20, 'S', 20]]),
        ];

        $stats = PlayerThrowStatistics::build(2, 30.0, $throws);

        self::assertSame(2, $stats->checkoutAttempts);
        self::assertSame(1, $stats->checkoutHits);
        self::assertSame(50.0, $stats->checkoutRate);
        self::assertSame([], $stats->favoriteDoubles);
        self::assertEquals(new PlayerProfileFinishDto(40, 2, ['S1', 'T13']), $stats->highestFinish);
    }

    /**
     * A double-out game from a double: the player busts with T20 in each of the given turns,
     * then checks out with the double.
     *
     * @return list<array{gameId:int, roundId:int, value:int, isDouble:bool, isTriple:bool, isBust:bool, score:int, startScore:int, doubleOut:bool, tripleOut:bool, placement:int|null}>
     */
    private function bustsThenCheckout(int $double, int $busts): array
    {
        $gameId = $this->nextGameId++;
        $throws = [];
        foreach (range(0, $busts) as $turn) {
            $isCheckout = $turn === $busts;
            $throws[] = [
                'gameId' => $gameId,
                'roundId' => $gameId * 1000 + $turn,
                'value' => $isCheckout ? $double : 60,
                'isDouble' => $isCheckout,
                'isTriple' => !$isCheckout,
                'isBust' => !$isCheckout,
                'score' => $isCheckout ? 0 : $double,
                'startScore' => $double,
                'doubleOut' => true,
                'tripleOut' => false,
                'placement' => 1,
            ];
        }

        return $throws;
    }

    /**
     * One turn in a new finished game the player won.
     *
     * @param 'single'|'double'|'triple' $outRule
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
            'tripleOut' => 'triple' === $outRule,
            'placement' => 1,
        ], $darts);
    }
}
