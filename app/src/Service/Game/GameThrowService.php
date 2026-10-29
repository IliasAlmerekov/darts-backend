<?php

/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\ThrowRecordingResultDto;
use App\Dto\ThrowDeltaDto;
use App\Dto\ThrowRequest;
use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\Round;
use App\Entity\RoundThrows;
use App\Exception\Game\GameNotFoundException;
use App\Exception\Game\GamePlayerNotActiveException;
use App\Exception\Game\GameThrowNotAllowedException;
use App\Exception\Game\PlayerAlreadyThrewThreeTimesException;
use App\Exception\Game\PlayerNotFoundInGameException;
use App\Enum\GameStatus;
use App\Repository\GameRepositoryInterface;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\RoundRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Security\GameAccessServiceInterface;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Override;

/**
 * Service to handle recording of game throws.
 * This class is responsible for updating the game state and recalculating the positions of the players.
 *
 * @psalm-suppress UnusedClass Reason: service is auto-wired by the container and used through DI.
 */
final readonly class GameThrowService implements GameThrowServiceInterface
{
    /**
     * @param GamePlayersRepositoryInterface $gamePlayersRepository
     * @param RoundRepositoryInterface       $roundRepository
     * @param RoundThrowsRepositoryInterface $roundThrowsRepository
     * @param EntityManagerInterface         $entityManager
     * @param GameAccessServiceInterface     $gameAccessService
     * @param GameRepositoryInterface        $gameRepository
     * @param NewestGameGuardInterface       $newestGameGuard
     * @param GameFinishServiceInterface     $gameFinishService
     * @param ActivePlayerResolverInterface  $activePlayerResolver
     * @param GameReopenServiceInterface     $gameReopenService
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        private GamePlayersRepositoryInterface $gamePlayersRepository,
        private RoundRepositoryInterface $roundRepository,
        private RoundThrowsRepositoryInterface $roundThrowsRepository,
        private EntityManagerInterface $entityManager,
        private GameAccessServiceInterface $gameAccessService,
        private GameRepositoryInterface $gameRepository,
        private NewestGameGuardInterface $newestGameGuard,
        private GameFinishServiceInterface $gameFinishService,
        private ?ActivePlayerResolverInterface $activePlayerResolver = null,
        private ?GameReopenServiceInterface $gameReopenService = null,
    ) {
    }

    /**
     * @param Game         $game
     * @param ThrowRequest $dto
     *
     * @return ThrowRecordingResultDto
     */
    #[Override]
    public function recordThrow(Game $game, ThrowRequest $dto): ThrowRecordingResultDto
    {
        return $this->entityManager->wrapInTransaction(function () use ($game, $dto): ThrowRecordingResultDto {
            if ($this->entityManager->contains($game)) {
                $this->gameRepository->refreshWithSeatsForUpdate($game);
            }

            return $this->recordThrowUnlocked($game, $dto);
        });
    }

    /**
     * @param int          $gameId
     * @param ThrowRequest $dto
     *
     * @return ThrowRecordingResultDto
     */
    #[Override]
    public function recordThrowByGameId(int $gameId, ThrowRequest $dto): ThrowRecordingResultDto
    {
        return $this->entityManager->wrapInTransaction(function () use ($gameId, $dto): ThrowRecordingResultDto {
            $game = $this->gameRepository->findOneByGameIdForUpdate($gameId);
            if (null === $game) {
                throw new GameNotFoundException();
            }

            return $this->recordThrowUnlocked($game, $dto);
        });
    }

    /**
     * @param Game $game
     *
     * @return ThrowDeltaDto|null
     */
    #[Override]
    public function undoLastThrow(Game $game): ?ThrowDeltaDto
    {
        $this->gameAccessService->assertPlayerInGameOrAdmin($game);

        $undoneThrow = $this->entityManager->wrapInTransaction(function () use ($game): ?ThrowDeltaDto {
            if ($this->entityManager->contains($game)) {
                $this->gameRepository->refreshWithSeatsForUpdate($game);
            }

            return $this->undoLastThrowUnlocked($game);
        });

        return $undoneThrow;
    }

    /**
     * @param Game         $game
     * @param ThrowRequest $dto
     *
     * @return ThrowRecordingResultDto
     */
    private function recordThrowUnlocked(Game $game, ThrowRequest $dto): ThrowRecordingResultDto
    {
        $user = $this->gameAccessService->assertPlayerInGameOrAdmin($game);

        $status = $game->getStatus();
        if (GameStatus::Started !== $status) {
            throw new GameThrowNotAllowedException($status);
        }

        if (null !== $dto->playerId) {
            $this->gameAccessService->assertPlayerMatches($user, $dto->playerId);
        }

        $player = $this->gamePlayersRepository->findOneBy([
            'game' => $game->getGameId(),
            'player' => $dto->playerId,
        ]);
        if (!$player instanceof GamePlayers) {
            throw new PlayerNotFoundInGameException();
        }

        $round = $this->getCurrentRound($game);
        $requestedPlayerId = $dto->playerId;
        if (null === $requestedPlayerId) {
            throw new PlayerNotFoundInGameException();
        }
        $roundStateSnapshot = $this->loadCurrentRoundStateSnapshot($game, $round);
        $this->assertActivePlayer($game, $requestedPlayerId, $roundStateSnapshot);

        $playerThrowsThisRound = TurnOrder::roundState($roundStateSnapshot, $requestedPlayerId)['throwsCount'];
        if ($playerThrowsThisRound >= 3) {
            throw new PlayerAlreadyThrewThreeTimesException();
        }

        $throwNumber = $playerThrowsThisRound + 1;
        $baseValue = $dto->value ?? 0;
        $isDouble = $dto->isDouble ?? false;
        $isTriple = $dto->isTriple ?? false;
        $currentScore = $player->getScore() ?? $game->getStartScore();
        $previousThrowsInRound = $this->roundThrowsRepository->findBy([
            'round' => $round,
            'player' => $player->getPlayer(),
        ]);
        $pointsScoredInRound = 0;
        foreach ($previousThrowsInRound as $previousThrow) {
            if (!$previousThrow->isBust()) {
                $pointsScoredInRound += $previousThrow->getValue() ?? 0;
            }
        }
        $scoring = ThrowScoring::score($currentScore, $pointsScoredInRound, $baseValue, $isDouble, $isTriple, $game->outRule());
        $finalValue = $scoring->value;
        $newScore = $scoring->score;
        $isBust = $scoring->bust;
        $roundThrow = new RoundThrows();
        $roundThrow->setGame($game);
        $roundThrow->setRound($round);
        $roundThrow->setPlayer($player->getPlayer());
        $roundThrow->setThrowNumber($throwNumber);
        $roundThrow->setValue($finalValue);
        $roundThrow->setIsDouble($isDouble);
        $roundThrow->setIsTriple($isTriple);
        $roundThrow->setTimestamp(new DateTime());
        $roundThrow->setIsBust($isBust);
        $roundThrow->setScore($newScore);
        $player->setScore($newScore);
        if (!$isBust) {
            // Check whether the player has won
            if (0 === $newScore) {
                $this->gameFinishService->recordCheckout($game, $player);
            }
        }

        $latestThrowPlayerName = PlayerDisplayName::forSeat($player);

        $this->entityManager->persist($roundThrow);
        $updatedRoundStateSnapshot = $roundStateSnapshot;
        $updatedRoundStateSnapshot[$requestedPlayerId] = [
            'throwsCount' => $playerThrowsThisRound + 1,
            'lastThrowNumber' => $throwNumber,
            'lastThrowValue' => $finalValue,
            'lastThrowBust' => $isBust,
        ];

        $hasAdvancedRound = $this->maybeAdvanceRound($game, $round, $updatedRoundStateSnapshot);
        $this->entityManager->flush();

        return new ThrowRecordingResultDto(
            latestThrow: $this->createLatestThrowSnapshot($roundThrow, $latestThrowPlayerName),
            currentRoundStateSnapshot: $hasAdvancedRound ? [] : $updatedRoundStateSnapshot,
            game: $game,
        );
    }

    /**
     * @param Game $game
     *
     * @return ThrowDeltaDto|null
     */
    private function undoLastThrowUnlocked(Game $game): ?ThrowDeltaDto
    {
        $status = $game->getStatus();
        if (!$status->canUndo()) {
            throw new GameThrowNotAllowedException($status);
        }

        $gameId = $game->getGameId();
        if (null === $gameId) {
            return null;
        }

        $lastThrow = $this->roundThrowsRepository->findEntityLatestForGame($gameId);
        if (!$lastThrow) {
            return null;
        }

        $reopenService = $this->gameReopenService ?? new GameReopenService(
            $this->entityManager,
            $this->gameAccessService,
            $this->gameRepository,
            $this->newestGameGuard,
        );
        $finished = GameStatus::Finished === $status;
        if ($finished) {
            $reopenService->assertCanReopen($game);
        }

        $seat = UndoThrowRules::findSeat($game, $lastThrow);
        $undoneThrow = $this->createThrowDeltaFromEntity($lastThrow, $seat);
        UndoThrowRules::restoreScore($game, $lastThrow, $seat, $this->roundThrowsRepository);
        if ($finished) {
            $reopenService->reopenFinished($game);
        } else {
            $game->takeBackPlacementsFromSeatsWithScoreLeft();
        }

        UndoThrowRules::reopenRound($game, $lastThrow, $this->roundRepository, $this->entityManager);
        $this->entityManager->remove($lastThrow);
        $this->entityManager->flush();

        return $undoneThrow;
    }

    /**
     * @param Game $game
     *
     * @return Round
     */
    private function getCurrentRound(Game $game): Round
    {
        $roundNumber = $game->getRound() ?? 1;
        $round = $this->roundRepository->findOneBy([
            'game' => $game,
            'roundNumber' => $roundNumber,
        ]);
        if (!$round instanceof Round) {
            $round = new Round();
            $round->setRoundNumber($roundNumber);
            $round->setGame($game);
            $round->setStartedAt(new DateTime());
            $this->entityManager->persist($round);
            $game->addRound($round);
        }

        return $round;
    }

    /**
     * @param Game             $game
     * @param Round            $currentRound
     * @param array<int,mixed> $roundStateSnapshot
     *
     * @return bool
     */
    private function maybeAdvanceRound(Game $game, Round $currentRound, array $roundStateSnapshot): bool
    {
        $playersCount = $game->getGamePlayers()->count();
        if (0 === $playersCount) {
            return false;
        }

        // Check whether all active players (score > 0) have thrown 3 darts
        foreach ($game->getGamePlayers() as $gp) {
            $player = $gp->getPlayer();
            if (null === $player) {
                continue;
            }

            $playerId = $player->getId();
            if (null === $playerId) {
                continue;
            }

            // Skip players who have already won (score = 0)
            $playerScore = $gp->getScore() ?? $game->getStartScore();
            if (0 === $playerScore) {
                continue;
            }

            $playerRoundState = TurnOrder::roundState($roundStateSnapshot, $playerId);
            if ($playerRoundState['throwsCount'] < 3 && false === $playerRoundState['lastThrowBust']) {
                return false;
                // Not all active players have thrown 3 darts yet
            }
        }

        // All players have thrown 3 darts, so advance to the next round
        $currentRound->setFinishedAt(new DateTime());
        $currentRoundNum = $game->getRound() ?? $currentRound->getRoundNumber() ?? 1;
        $nextRoundNumber = $currentRoundNum + 1;
        $game->setRound($nextRoundNumber);
        $nextRound = new Round();
        $nextRound->setRoundNumber($nextRoundNumber);
        $nextRound->setGame($game);
        $nextRound->setStartedAt(new DateTime());
        $game->addRound($nextRound);
        $this->entityManager->persist($nextRound);

        return true;
    }

    /**
     * @param Game             $game
     * @param int              $requestedPlayerId
     * @param array<int,mixed> $roundStateSnapshot
     *
     * @return void
     */
    private function assertActivePlayer(Game $game, int $requestedPlayerId, array $roundStateSnapshot): void
    {
        $activePlayerId = ($this->activePlayerResolver ?? new ActivePlayerResolver($this->roundThrowsRepository))
            ->resolveActivePlayer($game, $roundStateSnapshot);
        if (null !== $activePlayerId && $activePlayerId === $requestedPlayerId) {
            return;
        }

        throw new GamePlayerNotActiveException($requestedPlayerId, $activePlayerId);
    }

    /**
     * @param RoundThrows      $throw
     * @param GamePlayers|null $seat  the thrower's seat, from the game's loaded seats; null once the player left the room
     *
     * @return ThrowDeltaDto
     */
    private function createThrowDeltaFromEntity(RoundThrows $throw, ?GamePlayers $seat): ThrowDeltaDto
    {
        $player = $throw->getPlayer();
        $playerId = $player?->getId() ?? 0;
        $playerName = match (true) {
            null !== $seat => PlayerDisplayName::forSeat($seat),
            null !== $player => PlayerDisplayName::forUser($player),
            default => '',
        };
        $timestamp = $throw->getTimestamp();
        $storedValue = $throw->getValue() ?? 0;

        return new ThrowDeltaDto(
            id: $throw->getThrowId() ?? 0,
            playerId: $playerId,
            playerName: $playerName,
            value: DartThrow::baseValue($storedValue, $throw->isDouble(), $throw->isTriple()),
            isDouble: $throw->isDouble(),
            isTriple: $throw->isTriple(),
            isBust: $throw->isBust(),
            score: $throw->getScore() ?? 0,
            roundNumber: $throw->getRound()?->getRoundNumber() ?? 0,
            timestamp: $timestamp instanceof DateTimeInterface ? $timestamp->format(DateTimeInterface::ATOM) : (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        );
    }

    /**
     * @param RoundThrows $throw
     * @param string      $playerName
     *
     * @return array{id:int,playerId:int,roundNumber:int,throwNumber:int,value:int,isDouble:bool,isTriple:bool,isBust:bool,score:int,playerName:string,timestamp:string}|null
     */
    private function createLatestThrowSnapshot(RoundThrows $throw, string $playerName): ?array
    {
        $throwId = $throw->getThrowId();
        $playerId = $throw->getPlayer()?->getId();
        $roundNumber = $throw->getRound()?->getRoundNumber();
        $throwNumber = $throw->getThrowNumber();
        $value = $throw->getValue();
        $score = $throw->getScore();

        if (null === $throwId || null === $playerId || null === $roundNumber || null === $throwNumber || null === $value || null === $score) {
            return null;
        }

        $timestamp = $throw->getTimestamp();

        return [
            'id' => $throwId,
            'playerId' => $playerId,
            'roundNumber' => $roundNumber,
            'throwNumber' => $throwNumber,
            'value' => $value,
            'isDouble' => $throw->isDouble(),
            'isTriple' => $throw->isTriple(),
            'isBust' => $throw->isBust(),
            'score' => $score,
            'playerName' => $playerName,
            'timestamp' => $timestamp instanceof DateTimeInterface ? $timestamp->format(DateTimeInterface::ATOM) : (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param Game  $game
     * @param Round $round
     *
     * @return array<int, array{throwsCount:int,lastThrowNumber:int|null,lastThrowValue:int|null,lastThrowBust:bool}>
     */
    private function loadCurrentRoundStateSnapshot(Game $game, Round $round): array
    {
        $gameId = $game->getGameId();
        $roundNumber = $round->getRoundNumber();
        if (null === $gameId || null === $roundNumber) {
            return [];
        }

        return $this->roundThrowsRepository->findCurrentRoundStateSnapshot($gameId, $roundNumber);
    }
}
