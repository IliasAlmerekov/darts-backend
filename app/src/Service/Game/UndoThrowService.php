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
use App\Enum\GameStatus;
use App\Repository\RoundRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Override;

/** @psalm-suppress UnusedClass Reason: Symfony autowires this service. */
final readonly class UndoThrowService implements UndoThrowServiceInterface
{
    /**
     * @param RoundRepositoryInterface        $roundRepository
     * @param RoundThrowsRepositoryInterface  $roundThrowsRepository
     * @param EntityManagerInterface          $entityManager
     * @param FinishedGameTransitionInterface $finishedGameTransition
     */
    public function __construct(
        private RoundRepositoryInterface $roundRepository,
        private RoundThrowsRepositoryInterface $roundThrowsRepository,
        private EntityManagerInterface $entityManager,
        private FinishedGameTransitionInterface $finishedGameTransition,
    ) {
    }

    /**
     * @param Game $game
     *
     * @return RoundThrows|null
     */
    #[Override]
    public function undo(Game $game): ?RoundThrows
    {
        $status = $game->getStatus();

        $gameId = $game->getGameId();
        if (null === $gameId) {
            return null;
        }

        $lastThrow = $this->roundThrowsRepository->findEntityLatestForGame($gameId);
        if (!$lastThrow) {
            return null;
        }

        $finished = GameStatus::Finished === $status;
        if ($finished) {
            $this->finishedGameTransition->assertCanReopen($game);
        }

        $seat = $this->findSeat($game, $lastThrow);
        $undoneThrow = clone $lastThrow;
        $this->restoreScore($game, $lastThrow, $seat);
        if ($finished) {
            $this->finishedGameTransition->reopenFinished($game);
        } else {
            $game->takeBackPlacementsFromSeatsWithScoreLeft();
        }

        $this->reopenRound($game, $lastThrow);
        $this->entityManager->remove($lastThrow);
        $this->entityManager->flush();

        return $undoneThrow;
    }

    /**
     * @param Game        $game
     * @param RoundThrows $throw
     *
     * @return GamePlayers|null
     */
    private function findSeat(Game $game, RoundThrows $throw): ?GamePlayers
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
     * @param Game             $game
     * @param RoundThrows      $throw
     * @param GamePlayers|null $seat
     *
     * @return void
     */
    private function restoreScore(Game $game, RoundThrows $throw, ?GamePlayers $seat): void
    {
        $gameId = $game->getGameId();
        $playerId = $throw->getPlayer()?->getId();
        if (null === $gameId || null === $playerId) {
            return;
        }
        $throwId = $throw->getThrowId();
        $previous = null !== $throwId
            ? $this->roundThrowsRepository->findLatestForGameAndPlayerBeforeThrow($gameId, $playerId, $throwId)
            : $this->roundThrowsRepository->findLatestForGameAndPlayer($gameId, $playerId);
        if (null !== $seat) {
            $seat->setScore($previous?->getScore() ?? $game->getStartScore());
        }
    }

    /**
     * @param Game        $game
     * @param RoundThrows $throw
     *
     * @return void
     */
    private function reopenRound(Game $game, RoundThrows $throw): void
    {
        $round = $throw->getRound();
        $game->setRound($round?->getRoundNumber() ?? $game->getRound());
        $number = $round?->getRoundNumber();
        if (null === $round || null === $number) {
            return;
        }
        $next = $this->roundRepository->findOneBy(['game' => $game, 'roundNumber' => $number + 1]);
        if (!$next instanceof Round || !$next->getRoundThrows()->isEmpty()) {
            return;
        }
        $game->removeRound($next);
        $this->entityManager->remove($next);
        $round->setFinishedAt(null);
    }
}
