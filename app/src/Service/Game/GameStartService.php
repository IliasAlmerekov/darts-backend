<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Enum\OutRule;

use App\Dto\StartGameRequest;
use App\Entity\Game;
use App\Entity\Round;
use App\Exception\Game\ConflictingOutRulesException;
use App\Exception\Game\GameMustHaveValidPlayerCountException;
use App\Exception\Game\GameStartNotAllowedException;
use App\Exception\Game\PlayerPositionsCountMismatchException;
use App\Enum\GameStatus;
use App\Repository\GameRepositoryInterface;
use App\Repository\RoundRepositoryInterface;
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
     * @param GameSetupService           $gameSetupService
     * @param EntityManagerInterface     $entityManager
     * @param GameAccessServiceInterface $gameAccessService
     * @param RoundRepositoryInterface   $roundRepository
     * @param GameRepositoryInterface    $gameRepository
     */
    public function __construct(
        private GameSetupService $gameSetupService,
        private EntityManagerInterface $entityManager,
        private GameAccessServiceInterface $gameAccessService,
        private RoundRepositoryInterface $roundRepository,
        private GameRepositoryInterface $gameRepository,
    ) {
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
        // The request may hold the game from before a room create or join committed; start
        // from the locked, current game and seats.
        $this->gameRepository->refreshWithSeatsForUpdate($game);
        $this->gameAccessService->assertPlayerInGameOrAdmin($game);
        $status = $game->getStatus();
        if (GameStatus::Lobby !== $status) {
            throw new GameStartNotAllowedException($status);
        }
        $this->guardPlayerCount($game, $dto);
        $doubleOut = $dto->doubleOut ?? $game->isDoubleOut();
        $tripleOut = $dto->tripleOut ?? $game->isTripleOut();
        ConflictingOutRulesException::assertCompatible($doubleOut, $tripleOut);
        // The tablet runs one game at a time (ADR 0004).
        $this->gameRepository->abortStartedGames($game->getGameId());
        $game->setStatus(GameStatus::Started);
        if (null !== $dto->startScore) {
            $game->setStartScore($dto->startScore);
        }

        $game->setOutRule($doubleOut ? OutRule::Double : ($tripleOut ? OutRule::Triple : OutRule::Single));

        // Initialize the first round if none exists
        if (!$this->hasExistingRounds($game)) {
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
     * @param Game $game
     *
     * @return bool
     */
    private function hasExistingRounds(Game $game): bool
    {
        $gameId = $game->getGameId();
        if (null === $gameId) {
            return false === $game->getRounds()->isEmpty();
        }

        return $this->roundRepository->existsForGame($gameId);
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
        if ($count < 2 || $count > Game::MAX_PARTICIPANTS) {
            throw new GameMustHaveValidPlayerCountException();
        }

        if (null !== $dto->playerPositions && count($dto->playerPositions) !== $count) {
            throw new PlayerPositionsCountMismatchException();
        }
    }
}
