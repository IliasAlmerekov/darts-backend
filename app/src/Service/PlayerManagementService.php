<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Repository\GamePlayersRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;

/**
 * Service to handle player management.
 * This class is responsible for adding and removing players from games.
 */
readonly class PlayerManagementService
{
    public function __construct(
        private GamePlayersRepository  $gamePlayersRepository,
        private EntityManagerInterface $entityManager
    )
    {
    }

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
     * @throws ORMException
     */
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
     * @param list<int>|null $playerIds
     * @throws ORMException
     */
    public function copyPlayers(int $fromGameId, int $toGameId, ?array $playerIds = null): void
    {
        $oldGamePlayers = $this->gamePlayersRepository->findByGameId($fromGameId);
        $filter = $playerIds !== null ? array_map('intval', $playerIds) : null;

        foreach ($oldGamePlayers as $oldGamePlayer) {
            $player = $oldGamePlayer->getPlayer();
            if ($player !== null && ($filter === null || in_array($player->getId(), $filter, true))) {
                $this->addPlayer($toGameId, $player->getId());
            }
        }
    }
}
