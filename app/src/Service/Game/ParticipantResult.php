<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\GamePlayers;

/**
 * A participant's result in a game: placement order, display name and round average.
 * The game summary and the player's Profile both use it, so the two always agree.
 */
final class ParticipantResult
{
    /**
     * Orders seats by placement, seats without one last by remaining score.
     *
     * @param GamePlayers $a
     * @param GamePlayers $b
     *
     * @return int
     */
    public static function compareByPlacement(GamePlayers $a, GamePlayers $b): int
    {
        $placementA = $a->getPosition();
        $placementB = $b->getPosition();
        if (null === $placementA && null === $placementB) {
            return ($a->getScore() ?? PHP_INT_MAX) <=> ($b->getScore() ?? PHP_INT_MAX);
        }

        if (null === $placementA) {
            return 1;
        }

        if (null === $placementB) {
            return -1;
        }

        return $placementA <=> $placementB;
    }

    /**
     * @param GamePlayers $seat
     *
     * @return string|null
     */
    public static function displayName(GamePlayers $seat): ?string
    {
        if (null === $seat->getPlayer()) {
            return null;
        }

        $name = PlayerDisplayName::forSeat($seat);

        return '' !== $name ? $name : null;
    }

    /**
     * The number of rounds the game ran: its finished rounds, or the last round anyone threw in.
     *
     * @param int|null        $finishedRounds
     * @param array<int, int> $lastRoundByPlayer
     *
     * @return int|null
     */
    public static function maxRoundNumber(?int $finishedRounds, array $lastRoundByPlayer): ?int
    {
        if ([] === $lastRoundByPlayer) {
            return $finishedRounds;
        }

        return max($finishedRounds, max($lastRoundByPlayer));
    }

    /**
     * A participant who checked out (score 0) played their own rounds; everyone else played
     * every round of the game.
     *
     * @param int|null $score
     * @param int|null $ownRoundsPlayed
     * @param int|null $maxRoundNumber
     *
     * @return int|null
     */
    public static function roundsPlayed(?int $score, ?int $ownRoundsPlayed, ?int $maxRoundNumber): ?int
    {
        if (0 === $score) {
            return $ownRoundsPlayed ?? 0;
        }

        return $maxRoundNumber;
    }

    /**
     * @param float    $totalScore
     * @param int|null $roundsPlayed
     *
     * @return float
     */
    public static function roundAverage(float $totalScore, ?int $roundsPlayed): float
    {
        return $roundsPlayed > 0 ? $totalScore / (float) $roundsPlayed : 0.0;
    }
}
