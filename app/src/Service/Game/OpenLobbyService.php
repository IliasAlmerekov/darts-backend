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
use DateTime;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
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

        // Insert through DBAL: a failed ORM flush closes the EntityManager, and the
        // request still needs it after the index rejects the insert.
        $connection = $this->entityManager->getConnection();
        try {
            $connection->insert(
                'game',
                ['date' => new DateTime(), 'status' => GameStatus::Lobby->value],
                ['date' => Types::DATE_MUTABLE],
            );
        } catch (UniqueConstraintViolationException) {
            // A plain read inside a transaction would not see the other request's lobby. The
            // locking read needs a transaction, and rematch runs without one.
            $lobby = $connection->transactional(fn (): ?Game => $this->gameRepository->findOpenLobbyForUpdate())
                ?? throw new LogicException('The open lobby index rejected the insert, but no game is in the lobby.');

            return new OpenLobby($lobby, false);
        }

        $game = $this->entityManager->find(Game::class, (int) $connection->lastInsertId())
            ?? throw new LogicException('The new lobby game was not found after the insert.');

        return new OpenLobby($game, true);
    }
}
