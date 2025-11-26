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

    public function addPlayer(int $gameId, int $playerId): GamePlayers
    {
        $gamePlayer = new GamePlayers();
        $gamePlayer->setGame($this->entityManager->getReference(\App\Entity\Game::class, $gameId));
        $gamePlayer->setPlayer($this->entityManager->getReference(\App\Entity\User::class, $playerId));

        $this->entityManager->persist($gamePlayer);
        $this->entityManager->flush();

        return $gamePlayer;
    }

    public function copyPlayers(int $fromGameId, int $toGameId): void
    {
        $oldGamePlayers = $this->gamePlayersRepository->findByGameId($fromGameId);

        foreach ($oldGamePlayers as $oldGamePlayer) {
            $player = $oldGamePlayer->getPlayer();
            if ($player !== null) {
                $this->addPlayer($toGameId, $player->getId());
            }
        }
    }
}
