<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\ParticipantTotalsDto;
use App\Entity\Game;
use App\Entity\GamePlayers;

/**
 * A participant's result in a game: placement order, display name and three-dart average.
 * The game summary and the player's Profile both use it, so the two always agree.
 */
final class ParticipantResult
{
    /**
     * @param Game        $game
     * @param GamePlayers $seat
     *
     * @return bool
     */
    public static function recordCheckout(Game $game, GamePlayers $seat): bool
    {
        $finished = 0;
        $active = 0;
        foreach ($game->getGamePlayers() as $other) {
            $score = $other->getScore() ?? $game->getStartScore();
            if ($other !== $seat && 0 === $score) {
                $finished++;
            }
            if ($score > 0) {
                $active++;
            }
        }

        $seat->setPlacement($finished + 1);
        if (0 === $finished) {
            $game->setWinner($seat->getPlayer());
            foreach ($game->getGamePlayers() as $other) {
                $other->setIsWinner($other === $seat);
            }
        } else {
            $seat->setIsWinner(false);
        }

        return $active <= 1;
    }

    /**
     * @param Game $game
     *
     * @return void
     */
    public static function rankFinishedGame(Game $game): void
    {
        $finished = [];
        $remaining = [];
        foreach ($game->getGamePlayers() as $seat) {
            if (0 === ($seat->getScore() ?? $game->getStartScore())) {
                $finished[] = $seat;
            } else {
                $remaining[] = $seat;
            }
        }
        usort($finished, self::compareByPlacement(...));
        usort($remaining, static fn (GamePlayers $a, GamePlayers $b): int => (($a->getScore() ?? $game->getStartScore()) <=> ($b->getScore() ?? $game->getStartScore()))
        ?: TurnOrder::compare($a, $b));
        $seats = [...$finished, ...$remaining];
        foreach ($seats as $index => $seat) {
            $seat->setPlacement($index + 1);
            $seat->setIsWinner(0 === $index);
        }
        if ([] !== $seats) {
            $game->setWinner($seats[0]->getPlayer());
        }
    }
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
        $placementA = $a->getPlacement();
        $placementB = $b->getPlacement();
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
     * The three-dart average: points divided by darts thrown, times three. A participant who
     * threw nothing averages 0.
     *
     * @param ParticipantTotalsDto|null $totals
     *
     * @return float
     */
    public static function threeDartAverage(?ParticipantTotalsDto $totals): float
    {
        return null !== $totals && $totals->dartsThrown > 0
            ? $totals->totalScore * 3.0 / (float) $totals->dartsThrown
            : 0.0;
    }
}
