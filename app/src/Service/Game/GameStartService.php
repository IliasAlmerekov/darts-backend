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
use App\Exception\Game\GameNotFoundException;
use App\Exception\Game\GameStartNotAllowedException;
use App\Exception\Game\InvalidPlayerOrderException;
use App\Exception\Game\PlayerPositionsCountMismatchException;
use App\Enum\GameStatus;
use App\Repository\GameRepositoryInterface;
use App\Repository\RoundRepositoryInterface;
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
     * @param EntityManagerInterface         $entityManager
     * @param RoundRepositoryInterface       $roundRepository
     * @param GameRepositoryInterface        $gameRepository
     * @param LockedGameTransactionInterface $lockedGameTransaction
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RoundRepositoryInterface $roundRepository,
        private GameRepositoryInterface $gameRepository,
        private LockedGameTransactionInterface $lockedGameTransaction,
    ) {
    }

    /**
     * @param Game             $game
     * @param StartGameRequest $dto
     *
     * @return Game
     */
    #[Override]
    public function start(Game $game, StartGameRequest $dto): Game
    {
        $gameId = $game->getGameId() ?? throw new GameNotFoundException();

        return $this->lockedGameTransaction->runWithRetry($gameId, function (Game $game) use ($dto): Game {
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

            GameSetupRule::applyInitialScoresAndPositions($game, $dto->playerPositions);
            $this->entityManager->flush();

            return $game;
        });
    }

    /**
     * @param Game $game
     *
     * @return bool
     */
    private function hasExistingRounds(Game $game): bool
    {
        // The game was loaded by id, so it has one.
        return $this->roundRepository->existsForGame((int) $game->getGameId());
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

        if (null === $dto->playerPositions) {
            return;
        }

        if (count($dto->playerPositions) !== $count) {
            throw new PlayerPositionsCountMismatchException();
        }

        $participantIds = [];
        foreach ($game->getGamePlayers() as $seat) {
            $participantIds[(int) $seat->getPlayer()?->getId()] = true;
        }
        $seen = [];
        foreach ($dto->playerPositions as $playerId) {
            if (isset($seen[$playerId])) {
                throw new InvalidPlayerOrderException(sprintf('Duplicate playerId in positions payload: %d.', $playerId));
            }
            if (!isset($participantIds[$playerId])) {
                throw new InvalidPlayerOrderException(sprintf('Player %d is not part of the game.', $playerId));
            }
            $seen[$playerId] = true;
        }
    }
}
