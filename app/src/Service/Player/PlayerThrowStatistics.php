<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Player;

use App\Dto\PlayerProfileDoubleDto;
use App\Dto\PlayerProfileFieldHitsDto;
use App\Dto\PlayerProfileFinishDto;
use App\Dto\PlayerProfileHitDistributionByOutRuleDto;
use App\Dto\PlayerProfileMostHitSegmentDto;
use App\Dto\PlayerProfileStatsDto;
use App\Enum\OutRule;

/**
 * A player's Profile statistics from their throws in the counted games. The app records what
 * a dart hit, not what it aimed at, so the remaining score before a dart stands in for the aim.
 *
 * shortcut: every throw is judged by the out rule the game finished with, because the rule is
 * stored per game and a started game may still change it; store the rule per throw once a
 * mid-game change has to count correctly.
 *
 * @psalm-type ThrowRow = array{
 *     gameId:int,
 *     roundId:int,
 *     value:int,
 *     isDouble:bool,
 *     isTriple:bool,
 *     isBust:bool,
 *     score:int,
 *     startScore:int,
 *     outRule:OutRule,
 *     placement:int|null
 * }
 */
final class PlayerThrowStatistics
{
    private const string MISS = 'MISS';
    private const string BULL = 'BULL';
    private const string BULLSEYE = 'BULLSEYE';
    private const int FAVORITE_DOUBLES_LIMIT = 3;

    /**
     * @param int                $gamesPlayed  the counted games
     * @param float|null         $scoreAverage
     * @param iterable<ThrowRow> $throws       the player's throws in the counted games, oldest first
     *
     * @return PlayerProfileStatsDto
     */
    public static function build(int $gamesPlayed, ?float $scoreAverage, iterable $throws): PlayerProfileStatsDto
    {
        $fieldHits = array_fill_keys(PlayerProfileFieldHitsDto::FIELDS, 0);
        $fieldHitsByOutRule = [];
        foreach (OutRule::cases() as $outRule) {
            $fieldHitsByOutRule[$outRule->value] = $fieldHits;
        }
        $segmentHits = array_fill_keys(PlayerProfileMostHitSegmentDto::SEGMENTS, 0);
        $wonGames = [];
        $remainingByGame = [];
        $throwCount = 0;
        $checkoutHits = 0;
        $checkoutAttempts = 0;
        /** @var array<int, array{hits:int, attempts:int}> $doubles per remaining score */
        $doubles = [];
        $turnRoundId = null;
        $turnStart = 0;
        $turnDarts = [];
        $turnReadable = true;
        $highestFinish = null;

        foreach ($throws as $throw) {
            $throwCount++;
            $gameId = $throw['gameId'];
            $remaining = $remainingByGame[$gameId] ?? $throw['startScore'];
            $remainingByGame[$gameId] = $throw['score'];
            if (1 === $throw['placement']) {
                $wonGames[$gameId] = true;
            }

            if ($throw['roundId'] !== $turnRoundId) {
                $turnRoundId = $throw['roundId'];
                $turnStart = $remaining;
                $turnDarts = [];
                $turnReadable = true;
            }
            $segment = self::segment($throw);
            $label = self::dartLabel($segment, $throw);
            $turnReadable = $turnReadable && null !== $label;
            $turnDarts[] = $label ?? self::MISS;
            if (null !== $segment && isset($segmentHits[$segment])) {
                $segmentHits[$segment]++;
            }
            // MISS is no board field, and neither is a label such as T21 from a stored value.
            if (null !== $label && isset($fieldHits[$label])) {
                $fieldHits[$label]++;
                $fieldHitsByOutRule[$throw['outRule']->value][$label]++;
            }

            $isCheckout = !$throw['isBust'] && 0 === $throw['score'];
            $isAttempt = $throw['outRule']->canCheckOutInOneDart($remaining);
            if ($isAttempt) {
                $checkoutAttempts++;
                $checkoutHits += $isCheckout ? 1 : 0;
            }
            if ($isAttempt && OutRule::Double === $throw['outRule']) {
                $doubles[$remaining] ??= ['hits' => 0, 'attempts' => 0];
                $doubles[$remaining]['attempts']++;
                $doubles[$remaining]['hits'] += $isCheckout ? 1 : 0;
            }

            $darts = count($turnDarts);
            // A finish with an unreadable dart is left out rather than shown with a wrong label.
            if ($isCheckout && $turnReadable && (null === $highestFinish
                || $turnStart > $highestFinish->score
                || ($turnStart === $highestFinish->score && $darts < $highestFinish->darts))
            ) {
                $highestFinish = new PlayerProfileFinishDto($turnStart, $darts, $turnDarts);
            }
        }

        $wins = count($wonGames);

        return new PlayerProfileStatsDto(
            gamesPlayed: $gamesPlayed,
            scoreAverage: $scoreAverage,
            wins: $wins,
            winRate: self::percent($wins, $gamesPlayed),
            checkoutRate: self::percent($checkoutHits, $checkoutAttempts),
            checkoutHits: $checkoutHits,
            checkoutAttempts: $checkoutAttempts,
            hitDistribution: self::fieldHitsList($fieldHits),
            hitDistributionByOutRule: new PlayerProfileHitDistributionByOutRuleDto(
                singleout: self::fieldHitsList($fieldHitsByOutRule[OutRule::Single->value]),
                doubleout: self::fieldHitsList($fieldHitsByOutRule[OutRule::Double->value]),
                tripleout: self::fieldHitsList($fieldHitsByOutRule[OutRule::Triple->value]),
            ),
            mostHitSegment: self::mostHitSegment($segmentHits, $throwCount),
            favoriteDoubles: self::favoriteDoubles($doubles),
            highestFinish: $highestFinish,
        );
    }

    /**
     * @param array<string, int> $fieldHits hits per board field, in board field order
     *
     * @return list<PlayerProfileFieldHitsDto>
     */
    private static function fieldHitsList(array $fieldHits): array
    {
        return array_map(
            static fn(string $field, int $hits): PlayerProfileFieldHitsDto => new PlayerProfileFieldHitsDto($field, $hits),
            array_keys($fieldHits),
            array_values($fieldHits)
        );
    }

    /**
     * The board segment a dart hit: 1 to 20, BULL or BULLSEYE. Null for a miss, for a single above
     * 20 other than 25, which the throw endpoint used to accept but no dart can score, and for a
     * stored value that is no multiple of its ring, which only a manual database edit can produce.
     *
     * @param ThrowRow $throw
     *
     * @return string|null
     */
    private static function segment(array $throw): ?string
    {
        $value = $throw['value'];

        return match (true) {
            0 === $value => null,
            $throw['isTriple'] => 0 === $value % 3 ? (string) intdiv($value, 3) : null,
            $throw['isDouble'] && 50 === $value => self::BULLSEYE,
            $throw['isDouble'] => 0 === $value % 2 ? (string) intdiv($value, 2) : null,
            25 === $value => self::BULL,
            $value > 20 => null,
            default => (string) $value,
        };
    }

    /**
     * @param string|null $segment
     * @param ThrowRow    $throw
     *
     * @return string|null S<n>, D<n>, T<n>, BULL, BULLSEYE or MISS; null for an unreadable throw
     */
    private static function dartLabel(?string $segment, array $throw): ?string
    {
        return match (true) {
            null === $segment => 0 === $throw['value'] ? self::MISS : null,
            self::BULL === $segment, self::BULLSEYE === $segment => $segment,
            $throw['isTriple'] => 'T'.$segment,
            $throw['isDouble'] => 'D'.$segment,
            default => 'S'.$segment,
        };
    }

    /**
     * The segment with the most hits; a tie goes to the later, higher segment.
     *
     * @param array<string|int, int> $segmentHits in board order
     * @param int                    $throwCount  every dart thrown, misses included
     *
     * @return PlayerProfileMostHitSegmentDto|null
     */
    private static function mostHitSegment(array $segmentHits, int $throwCount): ?PlayerProfileMostHitSegmentDto
    {
        $mostHit = null;
        foreach ($segmentHits as $segment => $hits) {
            if ($hits > 0 && $hits >= ($mostHit?->hits ?? 0)) {
                $mostHit = new PlayerProfileMostHitSegmentDto((string) $segment, $hits, (float) self::percent($hits, $throwCount));
            }
        }

        return $mostHit;
    }

    /**
     * @param array<int, array{hits:int, attempts:int}> $doubles per remaining score
     *
     * @return list<PlayerProfileDoubleDto>
     */
    private static function favoriteDoubles(array $doubles): array
    {
        $favorites = [];
        foreach ($doubles as $score => $double) {
            if ($double['hits'] > 0) {
                $favorites[] = [
                    'score' => $score,
                    'rate' => $double['hits'] / $double['attempts'],
                    'dto' => new PlayerProfileDoubleDto(
                        50 === $score ? self::BULLSEYE : 'D'.intdiv($score, 2),
                        $double['hits'],
                        $double['attempts'],
                        (float) self::percent($double['hits'], $double['attempts']),
                    ),
                ];
            }
        }
        // Most hits first, then the better unrounded hit rate, then the higher double.
        usort($favorites, static function (array $a, array $b): int {
            return [$b['dto']->hits, $b['rate'], $b['score']] <=> [$a['dto']->hits, $a['rate'], $a['score']];
        });

        return array_map(
            static fn(array $favorite): PlayerProfileDoubleDto => $favorite['dto'],
            array_slice($favorites, 0, self::FAVORITE_DOUBLES_LIMIT)
        );
    }

    /**
     * @param int $part
     * @param int $whole
     *
     * @return float|null part per 100 of whole, rounded to one decimal; null when whole is 0
     */
    private static function percent(int $part, int $whole): ?float
    {
        return 0 === $whole ? null : round($part * 100 / $whole, 1);
    }
}
