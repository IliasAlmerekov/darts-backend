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
use App\Exception\Game\InvalidThrowException;
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
use Doctrine\DBAL\LockMode;
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
     * @param ActivePlayerResolverInterface  $activePlayerResolver
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
        private ?ActivePlayerResolverInterface $activePlayerResolver = null,
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
                $this->entityManager->lock($game, LockMode::PESSIMISTIC_WRITE);
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
                $this->entityManager->lock($game, LockMode::PESSIMISTIC_WRITE);
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

        $playerThrowsThisRound = $this->resolvePlayerRoundState($roundStateSnapshot, $requestedPlayerId)['throwsCount'];
        if ($playerThrowsThisRound >= 3) {
            throw new PlayerAlreadyThrewThreeTimesException();
        }

        $throwNumber = $playerThrowsThisRound + 1;
        $baseValue = $dto->value ?? 0;
        $isDouble = $dto->isDouble ?? false;
        $isTriple = $dto->isTriple ?? false;
        $this->assertValidThrowInput($baseValue, $isDouble, $isTriple);

        $finalValue = $baseValue;
        if ($isTriple) {
            $finalValue = $baseValue * 3;
        } elseif ($isDouble) {
            $finalValue = $baseValue * 2;
        }

        $currentScore = $player->getScore() ?? $game->getStartScore();
        $roundThrow = new RoundThrows();
        $roundThrow->setGame($game);
        $roundThrow->setRound($round);
        $roundThrow->setPlayer($player->getPlayer());
        $roundThrow->setThrowNumber($throwNumber);
        $roundThrow->setValue($finalValue);
        $roundThrow->setIsDouble($isDouble);
        $roundThrow->setIsTriple($isTriple);
        $roundThrow->setTimestamp(new DateTime());
        // Berechne den neuen Score
        $newScore = $currentScore - $finalValue;
        $wouldFinishGame = (0 === $newScore);
        // Hole Game-Mode Einstellungen
        $isDoubleOutMode = $game->isDoubleOut();
        $isTripleOutMode = $game->isTripleOut();
        // bust regeln
        $isBust =
            // Score unter 0
            ($newScore < 0) ||

            // Score = 1 bei Double-Out oder Triple-Out
            (($isDoubleOutMode || $isTripleOutMode) && 1 === $newScore) ||

            // Score = 2 bei Triple-Out
            ($isTripleOutMode && 2 === $newScore) ||

            // Finish ohne Double bei Double-Out
            ($wouldFinishGame && $isDoubleOutMode && !$isDouble) ||

            // Finish ohne Triple bei Triple-Out
            ($wouldFinishGame && $isTripleOutMode && !$isTriple);
        $roundThrow->setIsBust($isBust);
        if ($isBust) {
            // Bei bust Score auf Stand vor der Runde zurücksetzen
            $previousThrowsInRound = $this->roundThrowsRepository->findBy([
                'round' => $round,
                'player' => $player->getPlayer(),
            ]);
            $pointsScoredInRound = 0;
            foreach ($previousThrowsInRound as $prevThrow) {
                if (!$prevThrow->isBust()) {
                    $throwValue = $prevThrow->getValue();
                    if (null !== $throwValue) {
                        $pointsScoredInRound += $throwValue;
                    }
                }
            }

            $resetScore = $currentScore + $pointsScoredInRound;
            $roundThrow->setScore($resetScore);
            $player->setScore($resetScore);
        } else {
            // Kein Bust: Score normal aktualisieren
            $player->setScore($newScore);
            $roundThrow->setScore($newScore);
            // Check, ob der Spieler gewonnen hat
            if (0 === $newScore && $currentScore > 0) {
                $finishedPlayers = $this->gamePlayersRepository->countFinishedPlayers((int) $game->getGameId());
                $player->setPosition($finishedPlayers + 1);
                if (0 === $finishedPlayers) {
                    $game->setWinner($player->getPlayer());
                    $player->setIsWinner(true);
                    foreach ($game->getGamePlayers() as $gamePlayer) {
                        if ($gamePlayer !== $player) {
                            $gamePlayer->setIsWinner(false);
                        }
                    }
                } else {
                    $player->setIsWinner(false);
                }

                $activePlayers = 0;
                foreach ($game->getGamePlayers() as $gamePlayer) {
                    $playerScore = $gamePlayer->getScore() ?? $game->getStartScore();
                    if ($playerScore > 0) {
                        $activePlayers++;
                    }
                }
                if ($activePlayers <= 1) {
                    $game->setStatus(GameStatus::Finished);
                    $game->setFinishedAt(new DateTimeImmutable());
                    $this->normalizeFinishedGamePositions($game);
                }
            }
        }

        $latestThrowPlayerName = $this->resolveLatestThrowPlayerName($player);

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
        $gameId = $game->getGameId();
        if (null === $gameId) {
            return null;
        }

        $lastThrow = $this->roundThrowsRepository->findEntityLatestForGame($gameId);
        if (!$lastThrow) {
            return null;
        }

        $undoneThrow = $this->createThrowDeltaFromEntity($lastThrow);

        $player = $lastThrow->getPlayer();
        $playerId = $player?->getId();
        $lastThrowRoundNumber = $lastThrow->getRound()?->getRoundNumber() ?? $game->getRound();
        $lastThrowId = $lastThrow->getThrowId();
        $previousPlayerThrow = null;
        if (null !== $lastThrowId) {
            $this->roundThrowsRepository->findLatestForGameBeforeThrow($gameId, $lastThrowId);
            if (null !== $playerId) {
                $previousPlayerThrow = $this->roundThrowsRepository->findLatestForGameAndPlayerBeforeThrow($gameId, $playerId, $lastThrowId);
            }
        } elseif (null !== $playerId) {
            $previousPlayerThrow = $this->roundThrowsRepository->findLatestForGameAndPlayer($gameId, $playerId);
        }

        $gamePlayersByPlayerId = [];
        foreach ($game->getGamePlayers() as $gamePlayer) {
            $gamePlayerId = $gamePlayer->getPlayer()?->getId();
            if (null !== $gamePlayerId) {
                $gamePlayersByPlayerId[$gamePlayerId] = $gamePlayer;
            }
        }

        if (null !== $playerId && isset($gamePlayersByPlayerId[$playerId])) {
            $restoredScore = $previousPlayerThrow?->getScore() ?? $game->getStartScore();
            $gamePlayersByPlayerId[$playerId]->setScore($restoredScore);
        }

        // Wenn der letzte Wurf in einem finished Game rückgängig gemacht wird,
        // muss das Spiel wieder fortsetzbar sein.
        if (GameStatus::Finished === $game->getStatus()) {
            $game->setStatus(GameStatus::Started);
            $game->setFinishedAt(null);
        }

        foreach ($game->getGamePlayers() as $gamePlayer) {
            $currentPlayerScore = $gamePlayer->getScore() ?? $game->getStartScore();
            if ($currentPlayerScore > 0 && null !== $gamePlayer->getPosition()) {
                $gamePlayer->setPosition(0);
            }

            $gamePlayer->setIsWinner(false);
        }

        $game->setWinner(null);
        $game->setRound($lastThrowRoundNumber);

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

        // Wir prüfen, ob alle AKTIVEN Spieler (Score > 0) 3 Würfe gemacht haben
        foreach ($game->getGamePlayers() as $gp) {
            $player = $gp->getPlayer();
            if (null === $player) {
                continue;
            }

            $playerId = $player->getId();
            if (null === $playerId) {
                continue;
            }

            // Skip Spieler, die bereits gewonnen haben (Score = 0)
            $playerScore = $gp->getScore() ?? $game->getStartScore();
            if (0 === $playerScore) {
                continue;
            }

            $playerRoundState = $this->resolvePlayerRoundState($roundStateSnapshot, $playerId);
            if ($playerRoundState['throwsCount'] < 3 && false === $playerRoundState['lastThrowBust']) {
                return false;
                // Noch nicht alle AKTIVEN Spieler haben 3 Würfe gemacht
            }
        }

        // Alle Spieler haben 3 Würfe gemacht — wir gehen zur nächsten Runde über
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
     * Normalize final standings to unique positions (1..N).
     * Keeps finished players first (ordered by their existing finish position),
     * then appends unfinished players preserving their previous order.
     *
     * @param Game $game
     *
     * @return void
     */
    private function normalizeFinishedGamePositions(Game $game): void
    {
        $finishedPlayers = [];
        $unfinishedPlayers = [];
        foreach ($game->getGamePlayers() as $gamePlayer) {
            $score = $gamePlayer->getScore() ?? $game->getStartScore();
            if (0 === $score) {
                $finishedPlayers[] = $gamePlayer;

                continue;
            }

            $unfinishedPlayers[] = $gamePlayer;
        }

        $sortByPosition = static function (GamePlayers $left, GamePlayers $right): int {
            $leftPosition = $left->getPosition() ?? PHP_INT_MAX;
            $rightPosition = $right->getPosition() ?? PHP_INT_MAX;
            if ($leftPosition !== $rightPosition) {
                return $leftPosition <=> $rightPosition;
            }

            $leftId = $left->getGamePlayerId() ?? PHP_INT_MAX;
            $rightId = $right->getGamePlayerId() ?? PHP_INT_MAX;

            return $leftId <=> $rightId;
        };

        usort($finishedPlayers, $sortByPosition);
        usort($unfinishedPlayers, $sortByPosition);

        $position = 1;
        foreach ($finishedPlayers as $finishedPlayer) {
            $finishedPlayer->setPosition($position);
            $position++;
        }

        foreach ($unfinishedPlayers as $unfinishedPlayer) {
            $unfinishedPlayer->setPosition($position);
            $position++;
        }

        if ([] !== $finishedPlayers) {
            $winnerPlayer = $finishedPlayers[0];
            $game->setWinner($winnerPlayer->getPlayer());
            foreach ($game->getGamePlayers() as $gamePlayer) {
                $gamePlayer->setIsWinner($gamePlayer === $winnerPlayer);
            }
        }
    }

    /**
     * @param int  $baseValue
     * @param bool $isDouble
     * @param bool $isTriple
     *
     * @return void
     */
    private function assertValidThrowInput(int $baseValue, bool $isDouble, bool $isTriple): void
    {
        if ($isDouble && $isTriple) {
            throw new InvalidThrowException('Throw cannot be both double and triple at the same time.');
        }

        if ($isTriple && ($baseValue < 0 || $baseValue > 20)) {
            throw new InvalidThrowException('Triple throws require a base value between 0 and 20.');
        }

        if ($isDouble && ($baseValue < 0 || $baseValue > 20) && 25 !== $baseValue) {
            throw new InvalidThrowException('Double throws require a base value between 0 and 20, or 25 for bull.');
        }
    }

    /**
     * @param RoundThrows $throw
     *
     * @return ThrowDeltaDto
     */
    private function createThrowDeltaFromEntity(RoundThrows $throw): ThrowDeltaDto
    {
        $player = $throw->getPlayer();
        $playerId = $player?->getId() ?? 0;
        $playerName = $player?->getDisplayNameRaw() ?? $player?->getUsername() ?? '';
        $timestamp = $throw->getTimestamp();
        $storedValue = $throw->getValue() ?? 0;

        return new ThrowDeltaDto(
            id: $throw->getThrowId() ?? 0,
            playerId: $playerId,
            playerName: $playerName,
            value: $this->normalizeThrowValueForResponse($storedValue, $throw->isDouble(), $throw->isTriple()),
            isDouble: $throw->isDouble(),
            isTriple: $throw->isTriple(),
            isBust: $throw->isBust(),
            score: $throw->getScore() ?? 0,
            roundNumber: $throw->getRound()?->getRoundNumber() ?? 0,
            timestamp: $timestamp instanceof DateTimeInterface ? $timestamp->format(DateTimeInterface::ATOM) : (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        );
    }

    /**
     * Normalizes stored multiplied values back to the base board segment value for throw payloads.
     *
     * @param int  $storedValue
     * @param bool $isDouble
     * @param bool $isTriple
     *
     * @return int
     */
    private function normalizeThrowValueForResponse(int $storedValue, bool $isDouble, bool $isTriple): int
    {
        if ($isTriple && 0 === $storedValue % 3) {
            $baseValue = intdiv($storedValue, 3);
            if ($baseValue >= 0 && $baseValue <= 20) {
                return $baseValue;
            }
        }

        if ($isDouble && 0 === $storedValue % 2) {
            $baseValue = intdiv($storedValue, 2);
            if (($baseValue >= 0 && $baseValue <= 20) || 25 === $baseValue) {
                return $baseValue;
            }
        }

        return $storedValue;
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
     * @param GamePlayers $gamePlayer
     *
     * @return string
     */
    private function resolveLatestThrowPlayerName(GamePlayers $gamePlayer): string
    {
        $playerName = trim($gamePlayer->getDisplayNameSnapshot() ?? '');
        if ('' !== $playerName) {
            return $playerName;
        }

        $playerName = trim($gamePlayer->getPlayer()?->getDisplayNameRaw() ?? '');
        if ('' !== $playerName) {
            return $playerName;
        }

        return trim($gamePlayer->getPlayer()?->getUsername() ?? '');
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

    /**
     * @param array<int, array<string, int|bool|null>> $roundStateSnapshot
     * @param int                                      $playerId
     *
     * @return array{throwsCount:int,lastThrowNumber:int|null,lastThrowValue:int|null,lastThrowBust:bool}
     */
    private function resolvePlayerRoundState(array $roundStateSnapshot, int $playerId): array
    {
        $playerRoundState = $roundStateSnapshot[$playerId] ?? null;
        if (!is_array($playerRoundState)) {
            return [
                'throwsCount' => 0,
                'lastThrowNumber' => null,
                'lastThrowValue' => null,
                'lastThrowBust' => false,
            ];
        }

        return [
            'throwsCount' => (int) ($playerRoundState['throwsCount'] ?? 0),
            'lastThrowNumber' => isset($playerRoundState['lastThrowNumber']) ? (int) $playerRoundState['lastThrowNumber'] : null,
            'lastThrowValue' => isset($playerRoundState['lastThrowValue']) ? (int) $playerRoundState['lastThrowValue'] : null,
            'lastThrowBust' => true === ($playerRoundState['lastThrowBust'] ?? false),
        ];
    }
}
