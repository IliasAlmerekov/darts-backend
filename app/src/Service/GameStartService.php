<?php declare(strict_types=1);

namespace App\Service;

use App\Dto\StartGameRequest;
use App\Entity\Game;
use App\Entity\Round;
use App\Enum\GameStatus;
use Doctrine\ORM\EntityManagerInterface;

class GameStartService
{
    public function __construct(
        private readonly GameSetupService $gameSetupService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function start(Game $game, StartGameRequest $dto): void
    {
        $this->guardPlayerCount($game, $dto);

        $game->setStatus(GameStatus::Started);

        if ($dto->startScore !== null) {
            $game->setStartScore($dto->startScore);
        }

        if ($dto->doubleOut !== null) {
            $game->setDoubleOut($dto->doubleOut);
        }

        if ($dto->tripleOut !== null) {
            $game->setTripleOut($dto->tripleOut);
        }

        // Initialize first round if none exists
        if ($game->getRounds()->isEmpty()) {
            $round = new Round();
            $round->setRoundNumber(1);
            $round->setStartedAt(new \DateTime());
            $game->addRound($round);
            $game->setRound(1);
        }

        $this->gameSetupService->applyInitialScoresAndPositions($game, $dto->playerPositions);

        $this->entityManager->flush();
    }

    private function guardPlayerCount(Game $game, StartGameRequest $dto): void
    {
        $count = $game->getGamePlayers()->count();

        if ($count < 2 || $count > 10) {
            throw new \InvalidArgumentException('Game must have between 2 and 10 players to start.');
        }

        if ($dto->playerPositions !== null && $count !== count($dto->playerPositions)) {
            throw new \InvalidArgumentException('Player positions count must match players in game.');
        }
    }
}
