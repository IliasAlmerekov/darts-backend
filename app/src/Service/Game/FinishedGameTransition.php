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
use App\Repository\GameRepositoryInterface;
use Override;

/** @psalm-suppress UnusedClass Reason: Symfony autowires this service. */
final readonly class FinishedGameTransition implements FinishedGameTransitionInterface
{
    /**
     * @param GameRepositoryInterface $gameRepository
     */
    public function __construct(private GameRepositoryInterface $gameRepository)
    {
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
