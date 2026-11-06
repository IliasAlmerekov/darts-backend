<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

use OpenApi\Attributes as OA;

/**
 * @psalm-immutable
 * A player's own statistics. gamesPlayed and scoreAverage are computed like their row in
 * GET /api/players/stats; the rest comes from their throws in the same games.
 * Percentages run from 0 to 100, rounded to one decimal.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class PlayerProfileStatsDto
{
    /**
     * @param int                                      $gamesPlayed              finished games in which the player threw
     * @param float|null                               $scoreAverage             null when gamesPlayed is 0
     * @param int                                      $wins                     counted games with placement 1
     * @param float|null                               $winRate                  null when gamesPlayed is 0
     * @param float|null                               $checkoutRate             checkout hits per 100 attempts, null without attempts
     * @param int                                      $checkoutHits
     * @param int                                      $checkoutAttempts         darts thrown while one dart could check out
     * @param list<PlayerProfileFieldHitsDto>          $hitDistribution          always 62 entries, one per board field: S1, D1, T1 to T20, BULL, BULLSEYE
     * @param PlayerProfileHitDistributionByOutRuleDto $hitDistributionByOutRule hitDistribution split by each game's out rule
     * @param PlayerProfileMostHitSegmentDto|null      $mostHitSegment           null when no dart hit a segment
     * @param list<PlayerProfileDoubleDto>             $favoriteDoubles          top 3 in double-out games, empty without a hit
     * @param PlayerProfileFinishDto|null              $highestFinish            null without a finish
     */
    public function __construct(
        public int $gamesPlayed,
        #[OA\Property(description: 'Drei-Dart-Durchschnitt über alle Aufnahmen der gezählten Spiele; `null` ohne gezählte Spiele.')]
        public ?float $scoreAverage,
        #[OA\Property(description: 'Gezählte Spiele mit Platzierung 1.')]
        public int $wins,
        #[OA\Property(description: 'Siege pro 100 gezählte Spiele; `null` ohne gezählte Spiele.', minimum: 0, maximum: 100)]
        public ?float $winRate,
        #[OA\Property(description: 'Checkout-Treffer pro 100 Checkout-Versuche, über alle Out-Regeln; `null` ohne Versuche.', minimum: 0, maximum: 100)]
        public ?float $checkoutRate,
        #[OA\Property(description: 'Darts, die das Spiel für den Spieler beendet haben.')]
        public int $checkoutHits,
        #[OA\Property(description: 'Darts, geworfen bei einem Rest, den ein Dart nach der Out-Regel des Spiels auschecken kann; Busts eingeschlossen.')]
        public int $checkoutAttempts,
        #[OA\Property(description: 'Immer 62 Einträge, einer pro Board-Feld, in der Reihenfolge `S1`, `D1`, `T1` bis `S20`, `D20`, `T20`, `BULL`, `BULLSEYE`. Single, Double und Triple zählen getrennt, Busts zählen mit, Fehlwürfe nicht.', maxItems: 62, minItems: 62)]
        public array $hitDistribution,
        public PlayerProfileHitDistributionByOutRuleDto $hitDistributionByOutRule,
        #[OA\Property(description: 'Segment mit den meisten Treffern, bei Gleichstand das höhere; `null` ohne Treffer.')]
        public ?PlayerProfileMostHitSegmentDto $mostHitSegment,
        #[OA\Property(description: 'Nur Double-Out-Spiele: höchstens 3 Doubles mit mindestens einem Checkout, nach Treffern, dann Trefferquote, dann höherem Double.', maxItems: 3)]
        public array $favoriteDoubles,
        #[OA\Property(description: 'Höchstes Finish über alle Out-Regeln, bei Gleichstand das mit weniger Darts, dann das frühere; `null` ohne Finish.')]
        public ?PlayerProfileFinishDto $highestFinish,
    ) {
    }
}
