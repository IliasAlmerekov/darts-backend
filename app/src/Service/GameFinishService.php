<?php

namespace App\Service;

use App\Entity\Game;
use App\Enum\GameStatus;
use App\Repository\GamePlayersRepository;
use App\Repository\GameRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

class GameFinishService
{
    public function __construct(
        private GameRepository         $gameRepository,
        private EntityManagerInterface $entityManager,
        private GamePlayersRepository  $gamePlayersRepository,
        private UserRepository         $userRepository  // NEU
    )
    {
    }

    public function finishGame(int $gameId, ?int $winnerId = null, ?\DateTimeInterface $finishedAt = null): ?Game
    {
        $game = $this->gameRepository->find($gameId);

        if (!$game) {
            return null;
        }

        if ($winnerId !== null) {
            $winner = $this->userRepository->find($winnerId);
            $game->setWinner($winner);

            $player = $this->gamePlayersRepository->findOneBy(['game' => $gameId, 'player' => $winnerId]);
            if ($player) {
                $player->setIsWinner(true);
            }
        } else {
            $game->setWinner(null);
        }

        $game->setStatus(GameStatus::Finished);
        $game->setFinishedAt($finishedAt ?? new \DateTimeImmutable());

        $this->entityManager->flush();

        return $game;
    }
}
