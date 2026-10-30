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
use App\Repository\GameRepositoryInterface;
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
     * @param EntityManagerInterface         $entityManager
     * @param GameRepositoryInterface        $gameRepository
     * @param LockedGameTransactionInterface $lockedGameTransaction
     */
    public function __construct(private EntityManagerInterface $entityManager, private GameRepositoryInterface $gameRepository, private LockedGameTransactionInterface $lockedGameTransaction)
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

            $this->assertCanReopen($game);
            $this->reopenFinished($game);

            $this->entityManager->flush();

            return $game;
        });
    }

    /**
     * @param Game $game
     *
     * @return void
     */
    #[Override]
    public function assertCanReopen(Game $game): void
    {
        NewestGameRule::assert($game, [GameStatus::Finished], $this->gameRepository);
    }

    /**
     * @param Game $game
     *
     * @return void
     */
    #[Override]
    public function reopenFinished(Game $game): void
    {
        $this->gameRepository->abortStartedGames($game->getGameId());
        $game->setStatus(GameStatus::Started);
        $game->setFinishedAt(null);
        $game->takeBackPlacementsFromSeatsWithScoreLeft();
    }
}
