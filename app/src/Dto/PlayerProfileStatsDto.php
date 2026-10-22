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
     * @param int                                 $gamesPlayed      finished games with at least one finished round
     * @param float|null                          $scoreAverage     null when gamesPlayed is 0
     * @param int                                 $wins             counted games with placement 1
     * @param float|null                          $winRate          null when gamesPlayed is 0
     * @param float|null                          $checkoutRate     checkout hits per 100 attempts, null without attempts
     * @param int                                 $checkoutHits
     * @param int                                 $checkoutAttempts darts thrown while one dart could check out
     * @param list<PlayerProfileSegmentHitsDto>   $hitDistribution  always 22 entries: 1 to 20, BULL, BULLSEYE
     * @param PlayerProfileMostHitSegmentDto|null $mostHitSegment   null when no dart hit a segment
     * @param list<PlayerProfileDoubleDto>        $favoriteDoubles  top 3 in double-out games, empty without a hit
     * @param PlayerProfileFinishDto|null         $highestFinish    null without a finish
     */
    public function __construct(
        public int $gamesPlayed,
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
        #[OA\Property(description: 'Immer 22 Einträge in der Reihenfolge `1` bis `20`, `BULL`, `BULLSEYE`. Single, Double und Triple zählen für dasselbe Segment, Busts zählen mit, Fehlwürfe nicht.', maxItems: 22, minItems: 22)]
        public array $hitDistribution,
        #[OA\Property(description: 'Segment mit den meisten Treffern, bei Gleichstand das höhere; `null` ohne Treffer.')]
        public ?PlayerProfileMostHitSegmentDto $mostHitSegment,
        #[OA\Property(description: 'Nur Double-Out-Spiele: höchstens 3 Doubles mit mindestens einem Checkout, nach Treffern, dann Trefferquote, dann höherem Double.', maxItems: 3)]
        public array $favoriteDoubles,
        #[OA\Property(description: 'Höchstes Finish über alle Out-Regeln, bei Gleichstand das mit weniger Darts, dann das frühere; `null` ohne Finish.')]
        public ?PlayerProfileFinishDto $highestFinish,
    ) {
    }
}
