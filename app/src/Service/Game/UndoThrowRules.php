<?php

/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\Round;
use App\Entity\RoundThrows;
use App\Repository\RoundRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

/** Rules for restoring the state before a throw. */
final class UndoThrowRules
{
    /**
     * @param Game        $game
     * @param RoundThrows $throw
     *
     * @return GamePlayers|null
     */
    public static function findSeat(Game $game, RoundThrows $throw): ?GamePlayers
    {
        $playerId = $throw->getPlayer()?->getId();
        if (null === $playerId) {
            return null;
        }
        $matchingSeat = null;
        foreach ($game->getGamePlayers() as $seat) {
            if ($seat->getPlayer()?->getId() === $playerId) {
                $matchingSeat = $seat;
            }
        }

        return $matchingSeat;
    }

    /**
     * @param Game                           $game
     * @param RoundThrows                    $throw
     * @param GamePlayers|null               $seat
     * @param RoundThrowsRepositoryInterface $throws
     *
     * @return void
     */
    public static function restoreScore(Game $game, RoundThrows $throw, ?GamePlayers $seat, RoundThrowsRepositoryInterface $throws): void
    {
        $gameId = $game->getGameId();
        $playerId = $throw->getPlayer()?->getId();
        if (null === $gameId || null === $playerId) {
            return;
        }
        $throwId = $throw->getThrowId();
        $previous = null !== $throwId
            ? $throws->findLatestForGameAndPlayerBeforeThrow($gameId, $playerId, $throwId)
            : $throws->findLatestForGameAndPlayer($gameId, $playerId);
        if (null !== $seat) {
            $seat->setScore($previous?->getScore() ?? $game->getStartScore());
        }
    }

    /**
     * @param Game                     $game
     * @param RoundThrows              $throw
     * @param RoundRepositoryInterface $rounds
     * @param EntityManagerInterface   $entityManager
     *
     * @return void
     */
    public static function reopenRound(Game $game, RoundThrows $throw, RoundRepositoryInterface $rounds, EntityManagerInterface $entityManager): void
    {
        $round = $throw->getRound();
        $game->setRound($round?->getRoundNumber() ?? $game->getRound());
        $number = $round?->getRoundNumber();
        if (null === $round || null === $number) {
            return;
        }
        $next = $rounds->findOneBy(['game' => $game, 'roundNumber' => $number + 1]);
        if (!$next instanceof Round || !$next->getRoundThrows()->isEmpty()) {
            return;
        }
        $game->removeRound($next);
        $entityManager->remove($next);
        $round->setFinishedAt(null);
    }
}
