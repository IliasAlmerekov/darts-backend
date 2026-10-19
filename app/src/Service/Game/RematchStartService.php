<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\StartGameRequest;
use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Exception\Game\GameNotFoundException;
use App\Repository\GameRepositoryInterface;
use LogicException;
use Override;

/**
 * Orchestrates rematch creation and game start in one backend roundtrip.
 *
 * @psalm-suppress UnusedClass Reason: service is auto-wired and used via interface.
 */
final readonly class RematchStartService implements RematchStartServiceInterface
{
    /**
     * @param RematchServiceInterface   $rematchService
     * @param GameRoomServiceInterface  $gameRoomService
     * @param GameStartServiceInterface $gameStartService
     * @param GameRepositoryInterface   $gameRepository
     *
     * @return void
     */
    public function __construct(
        private RematchServiceInterface $rematchService,
        private GameRoomServiceInterface $gameRoomService,
        private GameStartServiceInterface $gameStartService,
        private GameRepositoryInterface $gameRepository,
    ) {
    }

    /**
     * @param int              $oldGameId
     * @param StartGameRequest $dto
     *
     * @return Game
     */
    #[Override]
    public function createAndStart(int $oldGameId, StartGameRequest $dto): Game
    {
        $rematch = $this->rematchService->createRematch($oldGameId);
        if (!($rematch['success'] ?? false)) {
            throw new GameNotFoundException();
        }

        $newGameId = $rematch['gameId'] ?? null;
        if (!is_int($newGameId)) {
            throw new LogicException('Rematch service did not return a valid gameId.');
        }

        $newGame = $this->gameRoomService->findGameById($newGameId);
        if (null === $newGame) {
            throw new LogicException(sprintf('Rematch game %d was not found after creation.', $newGameId));
        }

        // The seat collection must include participants who joined while the rematch waited.
        $this->gameRepository->refreshWithSeatsForUpdate($newGame);
        $this->gameStartService->start($newGame, $this->withLobbyParticipants($newGame, $dto));

        return $newGame;
    }

    /**
     * The client sends positions for the old game's players, but a reused lobby can hold more
     * participants (ADR 0003). They follow the sent order, in their lobby order.
     *
     * @param Game             $lobby
     * @param StartGameRequest $dto
     *
     * @return StartGameRequest
     */
    private function withLobbyParticipants(Game $lobby, StartGameRequest $dto): StartGameRequest
    {
        if (null === $dto->playerPositions) {
            return $dto;
        }

        $seats = $lobby->getGamePlayers()->toArray();
        usort($seats, static fn (GamePlayers $a, GamePlayers $b): int => $a->getPosition() <=> $b->getPosition());
        $positions = $dto->playerPositions;
        foreach ($seats as $seat) {
            $playerId = $seat->getPlayer()?->getId();
            if (null !== $playerId && !in_array($playerId, $positions, true)) {
                $positions[] = $playerId;
            }
        }

        return $dto->withPlayerPositions($positions);
    }
}
