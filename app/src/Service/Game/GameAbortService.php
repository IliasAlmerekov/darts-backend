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
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(private EntityManagerInterface $entityManager)
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
        $game->setStatus(GameStatus::Aborted);
        $this->entityManager->flush();
    }
}
