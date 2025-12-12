<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Player;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Repository\GamePlayersRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Override;

/**
 * Service to handle player management.
 * This class is responsible for adding and removing players from games.
 */
final readonly class PlayerManagementService implements PlayerManagementServiceInterface
{
    /**
     * @param GamePlayersRepositoryInterface $gamePlayersRepository
     * @param EntityManagerInterface         $entityManager
     */
    public function __construct(private GamePlayersRepositoryInterface $gamePlayersRepository, private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param int $gameId
     * @param int $playerId
     *
     * @return bool
     */
    #[Override]
    public function removePlayer(int $gameId, int $playerId): bool
    {
        $gamePlayer = $this->gamePlayersRepository->findOneBy([
            'game' => $gameId,
            'player' => $playerId,
        ]);
        if (null === $gamePlayer) {
            return false;
        }

        $this->entityManager->remove($gamePlayer);
        $this->entityManager->flush();

        return true;
    }

    /**
     * @param int $gameId
     * @param int $playerId
     *
     * @throws ORMException
     *
     * @return GamePlayers
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     *
     */
    #[Override]
    public function addPlayer(int $gameId, int $playerId): GamePlayers
    {
        $gamePlayer = new GamePlayers();
        $gamePlayer->setGame($this->entityManager->getReference(Game::class, $gameId));
        $gamePlayer->setPlayer($this->entityManager->getReference(User::class, $playerId));
        $this->entityManager->persist($gamePlayer);
        $this->entityManager->flush();

        return $gamePlayer;
    }

    /**
     * Copy players from one game to another. If a filter list is provided, only those players are copied.
     *
     * @param int            $fromGameId
     * @param int            $toGameId
     * @param list<int>|null $playerIds
     *
     * @throws ORMException
     */
    #[Override]
    public function copyPlayers(int $fromGameId, int $toGameId, ?array $playerIds = null): void
    {
        $oldGamePlayers = $this->gamePlayersRepository->findByGameId($fromGameId);
        $filter = null !== $playerIds ? array_map('intval', $playerIds) : null;
        foreach ($oldGamePlayers as $oldGamePlayer) {
            $player = $oldGamePlayer->getPlayer();
            $playerId = $player?->getId();
            if (null !== $playerId && (null === $filter || in_array($playerId, $filter, true))) {
                $this->addPlayer($toGameId, $playerId);
            }
        }
    }
}
