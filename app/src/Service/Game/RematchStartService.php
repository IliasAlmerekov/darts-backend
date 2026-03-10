<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\StartGameRequest;
use App\Entity\Game;
use App\Exception\Game\GameNotFoundException;
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
     *
     * @return void
     */
    public function __construct(
        private RematchServiceInterface $rematchService,
        private GameRoomServiceInterface $gameRoomService,
        private GameStartServiceInterface $gameStartService,
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

        $this->gameStartService->start($newGame, $dto);

        return $newGame;
    }
}
