<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use App\Enum\GameStatus;
use App\Exception\Game\GameNotFoundException;
use App\Exception\Game\GameReopenNotAllowedException;
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
     */
    public function __construct(private EntityManagerInterface $entityManager, private FinishedGameTransitionInterface $finishedGameTransition, private LockedGameTransactionInterface $lockedGameTransaction)
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

        return $this->lockedGameTransaction->runWithRetry($gameId, function (Game $game): Game {
            $status = $game->getStatus();
            if (GameStatus::Started === $status) {
                return $game;
            }

            if (GameStatus::Finished !== $status) {
                throw new GameReopenNotAllowedException($status);
            }

            $this->finishedGameTransition->assertCanReopen($game);
            $this->finishedGameTransition->reopenFinished($game);

            $this->entityManager->flush();

            return $game;
        });
    }
}
