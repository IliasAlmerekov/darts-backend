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
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
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
    private const int MAX_ATTEMPTS = 3;

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
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $found = $this->reuse($this->gameRepository->findOpenLobby());
            if (null !== $found) {
                return $found;
            }

            try {
                $gameId = $this->gameRepository->insertLobby();
                $game = $this->entityManager->find(Game::class, $gameId)
                    ?? throw new LogicException('The new lobby game was not found after the insert.');

                return new OpenLobby($game, true);
            } catch (UniqueConstraintViolationException) {
                // A plain read inside a transaction would not see the other request's lobby.
                $found = $this->reuse($this->gameRepository->findOpenLobbyForShare());
                if (null !== $found) {
                    return $found;
                }
                // That lobby started before this request locked it; look again.
            }
        }

        throw new LogicException(sprintf('No game stayed in the lobby over %d attempts.', self::MAX_ATTEMPTS));
    }

    /**
     * Returns the found lobby once it is locked, or null when there is none or it started first.
     *
     * @param Game|null $lobby
     *
     * @return OpenLobby|null
     */
    private function reuse(?Game $lobby): ?OpenLobby
    {
        return null !== $lobby && $this->lock($lobby) ? new OpenLobby($lobby, false) : null;
    }

    /**
     * Locks the game row until the transaction ends and reloads it, so requests that change
     * the same lobby run one after another. Two requests that both read the lobby with
     * findOpenLobbyForShare hold shared locks on this row and can deadlock here; MySQL then
     * rolls one back, and the caller's transaction runs again (ADR 0003).
     *
     * @param Game $lobby
     *
     * @return bool Whether the game is still in the lobby
     */
    private function lock(Game $lobby): bool
    {
        $this->entityManager->refresh($lobby, LockMode::PESSIMISTIC_WRITE);

        return GameStatus::Lobby === $lobby->getStatus();
    }
}
