<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use App\Entity\Round;
use App\Enum\GameStatus;
use App\Exception\Game\GameNotFoundException;
use App\Exception\Game\GameReopenNotAllowedException;
use App\Repository\RoundThrowsRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Override;

/**
 * Service responsible for reopening finished games.
 *
 * @psalm-suppress UnusedClass Reason: service is auto-wired by the container and used through DI.
 * @psalm-suppress PossiblyUnusedMethod Reason: constructor is used by Symfony autowiring.
 */
final readonly class GameReopenService implements GameReopenServiceInterface
{
    /**
     * @param EntityManagerInterface          $entityManager
     * @param FinishedGameTransitionInterface $finishedGameTransition
     * @param LockedGameTransactionInterface  $lockedGameTransaction
     * @param RoundThrowsRepositoryInterface  $roundThrowsRepository
     */
    public function __construct(private EntityManagerInterface $entityManager, private FinishedGameTransitionInterface $finishedGameTransition, private LockedGameTransactionInterface $lockedGameTransaction, private RoundThrowsRepositoryInterface $roundThrowsRepository)
    {
    }

    /**
     * @param Game $game
     *
     * @return Game
     */
    #[Override]
    public function reopen(Game $game): Game
    {
        $gameId = $game->getGameId() ?? throw new GameNotFoundException();

        return $this->lockedGameTransaction->runWithRetry($gameId, function (Game $game) use ($gameId): Game {
            $status = $game->getStatus();
            if (GameStatus::Started === $status) {
                return $game;
            }

            if (GameStatus::Finished !== $status) {
                throw new GameReopenNotAllowedException($status);
            }

            $this->finishedGameTransition->assertCanReopen($game);
            $this->finishedGameTransition->reopenFinished($game);
            // A finish by the last seat of a round leaves that round complete; play goes on in the next one.
            // Otherwise the seats still to throw play on in the current round, so it is open again.
            $roundNumber = $game->getRound() ?? 1;
            $snapshot = $this->roundThrowsRepository->findCurrentRoundStateSnapshot($gameId, $roundNumber);
            if (TurnOrder::isRoundComplete($game, $snapshot)) {
                $this->entityManager->persist($game->openNextRound());
            } else {
                $game->getRounds()->findFirst(static fn (int $key, Round $round): bool => $round->getRoundNumber() === $roundNumber)?->setFinishedAt(null);
            }

            $this->entityManager->flush();

            return $game;
        });
    }
}
