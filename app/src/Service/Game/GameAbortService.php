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
use App\Exception\Game\GameAbortNotAllowedException;
use App\Exception\Game\GameNotFoundException;

/**
 * Service to handle aborting games.
 * This class is responsible for setting the game status to aborted.
 *
 * @psalm-suppress UnusedClass Routed by Symfony framework
 */
final readonly class GameAbortService implements GameAbortServiceInterface
{
    /**
     * @param LockedGameTransactionInterface $lockedGameTransaction
     */
    public function __construct(private LockedGameTransactionInterface $lockedGameTransaction)
    {
    }

    /**
     * Aborts a game by setting its status to aborted.
     *
     * @param Game $game
     *
     * @return void
     */
    #[\Override]
    public function abortGame(Game $game): void
    {
        // Wait for a throw, undo or finish that holds the game lock, then abort the committed state.
        $gameId = $game->getGameId() ?? throw new GameNotFoundException();
        $this->lockedGameTransaction->run($gameId, static function (Game $game): void {
            $status = $game->getStatus();
            if (!$status->canAbort()) {
                throw new GameAbortNotAllowedException($status);
            }

            $game->setStatus(GameStatus::Aborted);
        });
    }
}
