<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use App\Repository\GameRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Override;

/**
 * Keeps at most one game in the lobby (ADR 0003).
 *
 * The unique index uniq_game_open_lobby enforces the rule. When two requests both find no
 * lobby, both insert one and the index rejects the later insert; that request then returns
 * the lobby the other request created.
 *
 * @psalm-suppress UnusedClass Reason: service is auto-wired and used via interface.
 */
final readonly class OpenLobbyService implements OpenLobbyServiceInterface
{
    /**
     * @param GameRepositoryInterface $gameRepository
     * @param EntityManagerInterface  $entityManager
     */
    public function __construct(private GameRepositoryInterface $gameRepository, private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return OpenLobby
     */
    #[Override]
    public function findOrCreate(): OpenLobby
    {
        $lobby = $this->gameRepository->findOpenLobby();
        if (null !== $lobby) {
            return new OpenLobby($lobby, false);
        }

        try {
            $gameId = $this->gameRepository->insertLobby();
        } catch (UniqueConstraintViolationException) {
            // A plain read inside a transaction would not see the other request's lobby. The
            // locking read needs a transaction, and rematch runs without one.
            $lobby = $this->entityManager->getConnection()->transactional(fn (): ?Game => $this->gameRepository->findOpenLobbyForShare())
                ?? throw new LogicException('The open lobby index rejected the insert, but no game is in the lobby.');

            return new OpenLobby($lobby, false);
        }

        $game = $this->entityManager->find(Game::class, $gameId)
            ?? throw new LogicException('The new lobby game was not found after the insert.');

        return new OpenLobby($game, true);
    }
}
