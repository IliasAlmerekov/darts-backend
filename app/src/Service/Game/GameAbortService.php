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
use App\Service\Security\GameAccessServiceInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service to handle aborting games.
 * This class is responsible for setting the game status to aborted.
 *
 * @psalm-suppress UnusedClass Routed by Symfony framework
 */
final readonly class GameAbortService implements GameAbortServiceInterface
{
    /**
     * @param EntityManagerInterface     $entityManager
     * @param GameAccessServiceInterface $gameAccessService
     * @param GameRepositoryInterface    $gameRepository
     */
    public function __construct(private EntityManagerInterface $entityManager, private GameAccessServiceInterface $gameAccessService, private GameRepositoryInterface $gameRepository)
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
        $this->entityManager->wrapInTransaction(function () use ($game): void {
            $this->gameRepository->refreshWithSeatsForUpdate($game);
            $this->gameAccessService->assertPlayerInGameOrAdmin($game);
            $game->setStatus(GameStatus::Aborted);
        });
    }
}
