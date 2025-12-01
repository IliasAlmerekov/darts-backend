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
        private EntityManagerInterface $entityManager,
        private PlayerManagementService $playerManagementService,
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

    /**
     * @param list<int>|null $includePlayerIds Explicit list of players to place into the new game
     * @param list<int>|null $excludePlayerIds Players to omit from the include list
     */
    public function createGameWithPreviousPlayers(?int $previousGameId = null, ?array $includePlayerIds = null, ?array $excludePlayerIds = null): Game
    {
        $game = $this->createGame();

        if ($includePlayerIds !== null) {
            $ids = array_values(array_unique(array_map('intval', $includePlayerIds)));
            if ($excludePlayerIds !== null) {
                $excludeSet = array_fill_keys(array_map('intval', $excludePlayerIds), true);
                $ids = array_values(array_filter($ids, static fn(int $pid): bool => !isset($excludeSet[$pid])));
            }

            if ($previousGameId !== null) {
                $previousGame = $this->findGameById($previousGameId);
                if ($previousGame !== null) {
                    $this->playerManagementService->copyPlayers($previousGameId, (int) $game->getGameId(), $ids);
                }
            } else {
                foreach ($ids as $playerId) {
                    $this->playerManagementService->addPlayer((int) $game->getGameId(), $playerId);
                }
            }
        }

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
