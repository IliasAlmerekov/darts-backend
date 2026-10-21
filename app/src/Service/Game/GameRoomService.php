<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use App\Exception\Game\PlayerNotFoundException;
use App\Repository\GameRepositoryInterface;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\UserRepositoryInterface;
use App\Service\Player\PlayerManagementService;
use App\Service\Security\GameAccessServiceInterface;
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
     * @param PlayerManagementService        $playerManagementService
     * @param GameAccessServiceInterface     $gameAccessService
     * @param OpenLobbyServiceInterface      $openLobbyService
     * @param UserRepositoryInterface        $userRepository
     */
    public function __construct(private GameRepositoryInterface $gameRepository, private GamePlayersRepositoryInterface $gamePlayersRepository, private PlayerManagementService $playerManagementService, private GameAccessServiceInterface $gameAccessService, private OpenLobbyServiceInterface $openLobbyService, private UserRepositoryInterface $userRepository)
    {
    }

    /**
     * Returns the open lobby unchanged when one exists; the arguments then change nothing.
     *
     * @param list<int>|null $includePlayerIds Explicit list of players to place into the new game
     * @param list<int>|null $excludePlayerIds Players to omit from the include list
     *
     * @return Game
     *
     * @throws ORMException
     * @throws PlayerNotFoundException When an included player id belongs to no user
     */
    #[Override]
    public function openRoom(?array $includePlayerIds = null, ?array $excludePlayerIds = null): Game
    {
        $this->gameAccessService->requireAuthenticatedUser();
        $lobby = $this->openLobbyService->findOrCreate();
        $game = $lobby->game;
        if (!$lobby->created) {
            return $game;
        }

        if (null !== $includePlayerIds) {
            $ids = array_values(array_unique($includePlayerIds));
            if (null !== $excludePlayerIds) {
                $excludeSet = array_fill_keys($excludePlayerIds, true);
                $ids = array_values(array_filter($ids, static fn(int $pid): bool => !isset($excludeSet[$pid])));
            }

            $players = [];
            foreach ($this->userRepository->findByIds($ids) as $player) {
                $players[(int) $player->getId()] = $player;
            }
            foreach ($ids as $playerId) {
                $player = $players[$playerId] ?? throw new PlayerNotFoundException();
                $this->playerManagementService->addPlayerEntity((int) $game->getGameId(), $player);
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
