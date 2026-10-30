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
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\RoundRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
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
     * @param LockedGameTransactionInterface $lockedGameTransaction
     * @param UndoThrowServiceInterface      $undoThrowService
     * @param GameFinishServiceInterface     $gameFinishService
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        private GamePlayersRepositoryInterface $gamePlayersRepository,
        private RoundRepositoryInterface $roundRepository,
        private RoundThrowsRepositoryInterface $roundThrowsRepository,
        private EntityManagerInterface $entityManager,
        private LockedGameTransactionInterface $lockedGameTransaction,
        private UndoThrowServiceInterface $undoThrowService,
        private GameFinishServiceInterface $gameFinishService,
    ) {
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
        return $this->lockedGameTransaction->run($gameId, fn (Game $game): ThrowRecordingResultDto => $this->recordThrowUnlocked($game, $dto));
    }

    /**
     * @param Game $game
     *
     * @return ThrowDeltaDto|null
     */
    #[Override]
    public function undoLastThrow(Game $game): ?ThrowDeltaDto
    {
        $gameId = $game->getGameId() ?? throw new GameNotFoundException();

        return $this->lockedGameTransaction->run($gameId, function (Game $game): ?ThrowDeltaDto {
            $status = $game->getStatus();
            if (!$status->canUndo()) {
                throw new GameThrowNotAllowedException($status);
            }

            return $this->undoThrowService->undo($game);
        });
    }

    /**
     * @param Game         $game
     * @param ThrowRequest $dto
     *
     * @return ThrowRecordingResultDto
     */
    private function recordThrowUnlocked(Game $game, ThrowRequest $dto): ThrowRecordingResultDto
    {
        $status = $game->getStatus();
        if (GameStatus::Started !== $status) {
            throw new GameThrowNotAllowedException($status);
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
        $updatedRoundStateSnapshot = TurnOrder::recordThrow($roundStateSnapshot, $requestedPlayerId, $throwNumber, $finalValue, $isBust);

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
        if (!TurnOrder::isRoundComplete($game, $roundStateSnapshot)) {
            return false;
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
        $activePlayerId = TurnOrder::activePlayerId($game, $roundStateSnapshot);
        if (null !== $activePlayerId && $activePlayerId === $requestedPlayerId) {
            return;
        }

        throw new GamePlayerNotActiveException($requestedPlayerId, $activePlayerId);
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
