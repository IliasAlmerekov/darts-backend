<?php

namespace App\Service;

use App\Entity\GamePlayers;
use App\Repository\GamePlayersRepository;
use Doctrine\ORM\EntityManagerInterface;

class PlayerManagementService
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
            'gameId' => $gameId,
            'playerId' => $playerId,
        ]);

        if (null === $gamePlayer) {
            return false;
        }

        $this->entityManager->remove($gamePlayer);
        $this->entityManager->flush();

        return true;
    }

    public function addPlayer(int $gameId, int $playerId): GamePlayers
    {
        $gamePlayer = new GamePlayers();
        $gamePlayer->setGameId($gameId);
        $gamePlayer->setPlayerId($playerId);

        $this->entityManager->persist($gamePlayer);
        $this->entityManager->flush();

        return $gamePlayer;
    }

    public function copyPlayers(int $fromGameId, int $toGameId): void
    {
        $oldGamePlayers = $this->gamePlayersRepository->findBy(['gameId' => $fromGameId]);

        foreach ($oldGamePlayers as $oldGamePlayer) {
            $this->addPlayer($toGameId, $oldGamePlayer->getPlayerId());
        }
    }
}
