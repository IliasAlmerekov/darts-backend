<?php

namespace App\Service;

use App\Entity\Game;
use App\Repository\GameRepository;
use App\Repository\GamePlayersRepository;
use Doctrine\ORM\EntityManagerInterface;

class GameRoomService
{
    public function __construct(
        private GameRepository         $gameRepository,
        private GamePlayersRepository  $gamePlayersRepository,
        private EntityManagerInterface $entityManager
    )
    {
    }

    public function createGame(): Game
    {
        $game = new Game();
        $game->setDate(new \DateTime());

        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $game;
    }

    public function findGameById(int $id): ?Game
    {
        return $this->gameRepository->find($id);
    }

    public function listGames(int $page, int $limit = 9): array
    {
        $offset = ($page - 1) * $limit;
        $games = $this->gameRepository->findBy([], null, $limit, $offset);
        $totalGames = $this->gameRepository->count([]);

        return [
            'games' => $games,
            'totalPages' => ceil($totalGames / $limit),
            'currentPage' => $page
        ];
    }

    public function getPlayerCount(int $gameId): int
    {
        return $this->gamePlayersRepository->count(['game' => $gameId]);
    }

    public function getPlayersWithUserInfo(int $gameId): array
    {
        return $this->gamePlayersRepository->findPlayersWithUserInfo($gameId);
    }
}
