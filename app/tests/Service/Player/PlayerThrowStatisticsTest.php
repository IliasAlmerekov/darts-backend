<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service\Player;

use App\Dto\PlayerProfileDoubleDto;
use App\Dto\PlayerProfileFieldHitsDto;
use App\Dto\PlayerProfileFinishDto;
use App\Dto\PlayerProfileMostHitSegmentDto;
use App\Dto\PlayerProfileStatsDto;
use App\Enum\OutRule;
use App\Service\Player\PlayerThrowStatistics;
use PHPUnit\Framework\TestCase;

/**
 * The tie-breaks and the hit counting of the Profile statistics. Each game here holds one turn
 * of the player.
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
     * The throws arrive one at a time, so the share cannot come from counting the input.
     */
    public function testThrowsArrivingOneAtATimeStillCountMissesInTheShare(): void
    {
        $throws = (function (): \Generator {
            yield from $this->turn(301, 'single', [[25, 'S', 276], [20, 'S', 256], [0, 'S', 256]]);
        })();

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

    public function testCheckoutAttemptsFollowEachOutRule(): void
    {
        foreach (['single' => 2, 'double' => 1, 'triple' => 1] as $rule => $attempts) {
            $throws = $this->turn(40, $rule, [[1, 'S', 39], [39, 'T', 0]]);
            self::assertSame($attempts, PlayerThrowStatistics::build(1, 30.0, $throws)->checkoutAttempts);
        }
    }

    /**
     * An old single above 20 that no dart can score hits no segment but still counts as a dart,
     * and its finish is left out instead of being labelled S60.
     */
    public function testUnreadableSingleHitsNoSegmentAndItsFinishIsLeftOut(): void
    {
        $stats = PlayerThrowStatistics::build(1, 110.0, $this->turn(110, 'single', [[60, 'S', 50], [50, 'D', 0]]));

        self::assertSame(1, $stats->checkoutAttempts);
        self::assertSame(1, $stats->checkoutHits);
        self::assertEquals(new PlayerProfileMostHitSegmentDto('BULLSEYE', 1, 50.0), $stats->mostHitSegment);
        self::assertSame(['BULLSEYE' => 1], $this->fieldsWithHits($stats));
        self::assertNull($stats->highestFinish);
    }

    public function testHitDistributionListsEveryBoardFieldInOrderWithoutThrows(): void
    {
        $stats = PlayerThrowStatistics::build(0, null, []);

        self::assertSame(
            [
                'S1', 'D1', 'T1', 'S2', 'D2', 'T2', 'S3', 'D3', 'T3', 'S4', 'D4', 'T4', 'S5', 'D5', 'T5',
                'S6', 'D6', 'T6', 'S7', 'D7', 'T7', 'S8', 'D8', 'T8', 'S9', 'D9', 'T9', 'S10', 'D10', 'T10',
                'S11', 'D11', 'T11', 'S12', 'D12', 'T12', 'S13', 'D13', 'T13', 'S14', 'D14', 'T14', 'S15', 'D15', 'T15',
                'S16', 'D16', 'T16', 'S17', 'D17', 'T17', 'S18', 'D18', 'T18', 'S19', 'D19', 'T19', 'S20', 'D20', 'T20',
                'BULL', 'BULLSEYE',
            ],
            array_map(static fn(PlayerProfileFieldHitsDto $entry): string => $entry->field, $stats->hitDistribution)
        );
        self::assertSame([], $this->fieldsWithHits($stats));
    }

    public function testSingleDoubleAndTripleCountInTheirOwnFieldsAndMostHitSegmentSumsThem(): void
    {
        $throws = $this->turn(501, 'single', [
            ...array_fill(0, 15, [20, 'S', 501]),
            ...array_fill(0, 3, [40, 'D', 501]),
            ...array_fill(0, 12, [60, 'T', 501]),
            ...array_fill(0, 90, [0, 'S', 501]),
        ]);

        $stats = PlayerThrowStatistics::build(1, 60.0, $throws);

        self::assertSame(['S20' => 15, 'D20' => 3, 'T20' => 12], $this->fieldsWithHits($stats));
        self::assertEquals(new PlayerProfileMostHitSegmentDto('20', 30, 25.0), $stats->mostHitSegment);
    }

    public function testBullAndBullseyeCountOnlyInTheirOwnFields(): void
    {
        $throws = $this->turn(501, 'single', [[25, 'S', 476], [25, 'S', 451], [50, 'D', 401]]);

        self::assertSame(['BULL' => 2, 'BULLSEYE' => 1], $this->fieldsWithHits(PlayerThrowStatistics::build(1, 100.0, $throws)));
    }

    public function testBustDartCountsInItsField(): void
    {
        $stats = PlayerThrowStatistics::build(1, 20.0, $this->bustsThenCheckout(40, 2));

        self::assertSame(['D20' => 1, 'T20' => 2], $this->fieldsWithHits($stats));
    }

    /**
     * T21, D22 and T25 have a dart label but are no board field, so they must not add an entry.
     */
    public function testThrowsMatchingNoBoardFieldCountInNoFieldButStillCountAsDarts(): void
    {
        $throws = $this->turn(501, 'single', [
            [0, 'S', 501],
            [60, 'S', 441],
            [63, 'T', 378],
            [44, 'D', 334],
            [75, 'T', 259],
            [20, 'S', 239],
        ]);

        $stats = PlayerThrowStatistics::build(1, 262.0, $throws);

        self::assertCount(62, $stats->hitDistribution);
        self::assertSame(['S20' => 1], $this->fieldsWithHits($stats));
        self::assertEquals(new PlayerProfileMostHitSegmentDto('20', 1, 16.7), $stats->mostHitSegment);
    }

    /**
     * A triple of 61 or a double of 41 is no multiple of its ring, so no dart can score it.
     */
    public function testValueThatIsNoMultipleOfItsRingCountsInNoFieldOrSegmentButAsADart(): void
    {
        $throws = $this->turn(501, 'single', [[61, 'T', 440], [41, 'D', 399], [20, 'S', 379]]);

        $stats = PlayerThrowStatistics::build(1, 122.0, $throws);

        self::assertSame(['S20' => 1], $this->fieldsWithHits($stats));
        self::assertEquals(new PlayerProfileMostHitSegmentDto('20', 1, 33.3), $stats->mostHitSegment);
    }

    public function testFinishWithValueThatIsNoMultipleOfItsRingIsLeftOut(): void
    {
        $throws = $this->turn(81, 'single', [[61, 'T', 20], [20, 'S', 0]]);

        self::assertNull(PlayerThrowStatistics::build(1, 81.0, $throws)->highestFinish);
    }

    /**
     * @return array<string, int> the board fields that were hit, in the listed order
     */
    private function fieldsWithHits(PlayerProfileStatsDto $stats): array
    {
        $hits = [];
        foreach ($stats->hitDistribution as $entry) {
            if ($entry->hits > 0) {
                $hits[$entry->field] = $entry->hits;
            }
        }

        return $hits;
    }

    /**
     * A double-out game from a double: the player busts with T20 in each of the given turns,
     * then checks out with the double.
     *
     * @return list<array{gameId:int, roundId:int, value:int, isDouble:bool, isTriple:bool, isBust:bool, score:int, startScore:int, outRule:OutRule, placement:int|null}>
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
                'outRule' => OutRule::Double,
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
     * @return list<array{gameId:int, roundId:int, value:int, isDouble:bool, isTriple:bool, isBust:bool, score:int, startScore:int, outRule:OutRule, placement:int|null}>
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
            'outRule' => OutRule::from($outRule.'out'),
            'placement' => 1,
        ], $darts);
    }
}
