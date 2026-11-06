<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\GameSettingsRequest;
use App\Dto\RoomCreateRequest;
use App\Entity\Game;
use App\Exception\Game\ConflictingOutRulesException;
use App\Exception\Game\GameRoomFullException;
use App\Exception\Game\PlayerNotFoundException;
use App\Service\Persistence\RetryingTransactionInterface;
use App\Service\Player\PlayerManagementServiceInterface;
use App\Service\Security\GameAccessServiceInterface;
use Doctrine\ORM\Exception\ORMException;
use Override;

/**
 * Service for creating and managing game rooms.
 */
final readonly class GameRoomService implements GameRoomServiceInterface
{
    /**
     * @param PlayerManagementServiceInterface $playerManagementService
     * @param GameAccessServiceInterface       $gameAccessService
     * @param OpenLobbyServiceInterface        $openLobbyService
     * @param RetryingTransactionInterface     $transaction
     * @param GameSettingsServiceInterface     $gameSettingsService
     */
    public function __construct(private PlayerManagementServiceInterface $playerManagementService, private GameAccessServiceInterface $gameAccessService, private OpenLobbyServiceInterface $openLobbyService, private RetryingTransactionInterface $transaction, private GameSettingsServiceInterface $gameSettingsService)
    {
    }

    /**
     * An existing open lobby keeps its players; only the settings of the request apply to it.
     *
     * @param RoomCreateRequest $request
     *
     * @return Game
     *
     * @throws ORMException
     * @throws PlayerNotFoundException      When an included player id belongs to no user
     * @throws GameRoomFullException        When a new lobby would seat more than Game::MAX_PARTICIPANTS players
     * @throws ConflictingOutRulesException When the lobby would end up with double out and triple out
     */
    #[Override]
    public function openRoom(RoomCreateRequest $request): Game
    {
        return $this->transaction->run(function () use ($request): Game {
            $this->gameAccessService->requireAuthenticatedUser();
            $lobby = $this->openLobbyService->findOrCreate();
            $game = $lobby->game;
            if ($lobby->created) {
                $this->seatPlayers($game, $request->playerIds, $request->excludePlayerIds);
            }

            if (null !== $request->startScore || null !== $request->doubleOut || null !== $request->tripleOut) {
                $this->gameSettingsService->applySettings($game, new GameSettingsRequest(
                    startScore: $request->startScore,
                    doubleOut: $request->doubleOut,
                    tripleOut: $request->tripleOut,
                ));
            }

            return $game;
        });
    }

    /**
     * @param Game           $game
     * @param list<int>|null $includePlayerIds Explicit list of players to place into the new game
     * @param list<int>|null $excludePlayerIds Players to omit from the include list
     *
     * @return void
     *
     * @throws ORMException
     * @throws PlayerNotFoundException
     * @throws GameRoomFullException
     */
    private function seatPlayers(Game $game, ?array $includePlayerIds, ?array $excludePlayerIds): void
    {
        if (null !== $includePlayerIds) {
            $ids = array_values(array_unique($includePlayerIds));
            if (null !== $excludePlayerIds) {
                $excludeSet = array_fill_keys($excludePlayerIds, true);
                $ids = array_values(array_filter($ids, static fn(int $pid): bool => !isset($excludeSet[$pid])));
            }
            $this->playerManagementService->addPlayers((int) $game->getGameId(), $ids);
        }
    }
}
