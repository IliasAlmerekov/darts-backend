<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\StartGameRequest;
use App\Entity\Game;
use App\Entity\Round;
use App\Exception\Game\GameMustHaveValidPlayerCountException;
use App\Exception\Game\PlayerPositionsCountMismatchException;
use App\Enum\GameStatus;
use App\Service\Security\GameAccessServiceInterface;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Override;

/**
 * Service to start a game and initialize settings.
 *
 * @psalm-suppress UnusedClass
 *
 */
final readonly class GameStartService implements GameStartServiceInterface
{
    /**
     * @param GameSetupService            $gameSetupService
     * @param EntityManagerInterface      $entityManager
     * @param GameAccessServiceInterface  $gameAccessService
     */
    public function __construct(private GameSetupService $gameSetupService, private EntityManagerInterface $entityManager, private GameAccessServiceInterface $gameAccessService)
    {
    }

    /**
     * @param Game             $game
     * @param StartGameRequest $dto
     *
     * @return void
     */
    #[Override]
    public function start(Game $game, StartGameRequest $dto): void
    {
        $this->gameAccessService->assertPlayerInGameOrAdmin($game);
        $this->guardPlayerCount($game, $dto);
        $game->setStatus(GameStatus::Started);
        if (null !== $dto->startScore) {
            $game->setStartScore($dto->startScore);
        }

        if (null !== $dto->doubleOut) {
            $game->setDoubleOut($dto->doubleOut);
        }

        if (null !== $dto->tripleOut) {
            $game->setTripleOut($dto->tripleOut);
        }

        // Initialize the first round if none exists
        if ($game->getRounds()->isEmpty()) {
            $round = new Round();
            $round->setRoundNumber(1);
            $round->setStartedAt(new DateTime());
            $game->addRound($round);
            $game->setRound(1);
        }

        $this->gameSetupService->applyInitialScoresAndPositions($game, $dto->playerPositions);
        $this->entityManager->flush();
    }

    /**
     * @param Game             $game
     * @param StartGameRequest $dto
     *
     * @return void
     */
    private function guardPlayerCount(Game $game, StartGameRequest $dto): void
    {
        $count = $game->getGamePlayers()->count();
        if ($count < 2 || $count > 10) {
            throw new GameMustHaveValidPlayerCountException();
        }

        if (null !== $dto->playerPositions && count($dto->playerPositions) !== $count) {
            throw new PlayerPositionsCountMismatchException();
        }
    }
}
