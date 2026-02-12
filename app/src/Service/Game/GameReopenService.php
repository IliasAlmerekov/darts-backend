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
use App\Exception\Game\GameReopenNotAllowedException;
use App\Service\Security\GameAccessServiceInterface;
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
     * @param EntityManagerInterface     $entityManager
     * @param GameAccessServiceInterface $gameAccessService
     */
    public function __construct(private EntityManagerInterface $entityManager, private GameAccessServiceInterface $gameAccessService)
    {
    }

    /**
     * @param Game $game
     *
     * @return void
     */
    #[Override]
    public function reopen(Game $game): void
    {
        $this->gameAccessService->assertPlayerInGameOrAdmin($game);

        $status = $game->getStatus();
        if (GameStatus::Started === $status) {
            return;
        }

        if (GameStatus::Finished !== $status) {
            throw new GameReopenNotAllowedException($status);
        }

        $game->setStatus(GameStatus::Started);
        $game->setFinishedAt(null);
        $game->setWinner(null);

        foreach ($game->getGamePlayers() as $gamePlayer) {
            $gamePlayer->setIsWinner(false);
        }

        $this->entityManager->flush();
    }
}
