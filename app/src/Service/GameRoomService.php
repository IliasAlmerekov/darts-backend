<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Game;
use App\Repository\GameRepositoryInterface;
use App\Repository\GamePlayersRepositoryInterface;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Override;

/**
 * Service for creating and managing game rooms.
 */
final readonly class GameRoomService implements GameRoomServiceInterface
{
    /**
     * @param GameRepositoryInterface        $gameRepository
     * @param GamePlayersRepositoryInterface $gamePlayersRepository
     * @param EntityManagerInterface         $entityManager
     * @param PlayerManagementService        $playerManagementService
     */
    public function __construct(
        private GameRepositoryInterface $gameRepository,
        private GamePlayersRepositoryInterface $gamePlayersRepository,
        private EntityManagerInterface $entityManager,
        private PlayerManagementService $playerManagementService,
    ) {
    }

    /**
     * @return Game
     */
    #[Override]
    public function createGame(): Game
    {
        $game = new Game();
        $game->setDate(new DateTime());
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $game;
    }

    /**
     * @param int|null       $previousGameId
     * @param list<int>|null $includePlayerIds Explicit list of players to place into the new game
     * @param list<int>|null $excludePlayerIds Players to omit from the include list
     *
     * @return Game
     *
     * @throws ORMException
     */
    #[Override]
    public function createGameWithPreviousPlayers(
        ?int $previousGameId = null,
        ?array $includePlayerIds = null,
        ?array $excludePlayerIds = null
    ): Game {
        $game = $this->createGame();
        if (null !== $includePlayerIds) {
            $ids = array_values(array_unique(array_map('intval', $includePlayerIds)));
            if (null !== $excludePlayerIds) {
                $excludeSet = array_fill_keys(array_map('intval', $excludePlayerIds), true);
                $ids = array_values(array_filter($ids, static fn(int $pid): bool => !isset($excludeSet[$pid])));
            }

            if (null !== $previousGameId) {
                $previousGame = $this->findGameById($previousGameId);
                if (null !== $previousGame) {
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

    /**
     * @param int $id
     *
     * @return Game|null
     */
    #[Override]
    public function findGameById(int $id): ?Game
    {
        $game = $this->gameRepository->find($id);

        return $game instanceof Game ? $game : null;
    }

    /**
     * @param int $gameId
     *
     * @return array<int, array<string, mixed>>
     */
    #[Override]
    public function getPlayersWithUserInfo(int $gameId): array
    {
        return $this->gamePlayersRepository->findPlayersWithUserInfo($gameId);
    }
}
