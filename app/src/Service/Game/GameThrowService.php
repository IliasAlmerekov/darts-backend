<?php

/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\ThrowRequest;
use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\Round;
use App\Entity\RoundThrows;
use App\Exception\Game\InvalidThrowException;
use App\Exception\Game\GamePlayerNotActiveException;
use App\Exception\Game\GameThrowNotAllowedException;
use App\Exception\Game\PlayerAlreadyThrewThreeTimesException;
use App\Exception\Game\PlayerNotFoundInGameException;
use App\Enum\GameStatus;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\RoundRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Security\GameAccessServiceInterface;
use DateTime;
use DateTimeImmutable;
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
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        private GamePlayersRepositoryInterface $gamePlayersRepository,
        private RoundRepositoryInterface $roundRepository,
        private RoundThrowsRepositoryInterface $roundThrowsRepository,
        private EntityManagerInterface $entityManager,
        private GameAccessServiceInterface $gameAccessService,
    ) {
    }

    /**
     * @param Game         $game
     * @param ThrowRequest $dto
     *
     * @return void
     */
    #[Override]
    public function recordThrow(Game $game, ThrowRequest $dto): void
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
        if (null === $player) {
            throw new PlayerNotFoundInGameException();
        }

        $round = $this->getCurrentRound($game);
        $requestedPlayerId = $dto->playerId;
        if (null === $requestedPlayerId) {
            throw new PlayerNotFoundInGameException();
        }
        $this->assertActivePlayer($game, $round, $requestedPlayerId);

        $playerThrowsThisRound = $this->roundThrowsRepository->count([
            'round' => $round,
            'player' => $player->getPlayer(),
        ]);
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

        $this->entityManager->persist($roundThrow);
        $this->entityManager->flush();
        $this->maybeAdvanceRound($game, $round);
    }

    /**
     * @param Game $game
     *
     * @return void
     */
    #[Override]
    public function undoLastThrow(Game $game): void
    {
        $this->gameAccessService->assertPlayerInGameOrAdmin($game);

        $gameId = $game->getGameId();
        if (null === $gameId) {
            return;
        }

        $lastThrow = $this->roundThrowsRepository->findEntityLatestForGame($gameId);
        if (!$lastThrow) {
            return;
        }

        $player = $lastThrow->getPlayer();
        $lastThrowRoundNumber = $lastThrow->getRound()?->getRoundNumber() ?? $game->getRound();
        $this->entityManager->remove($lastThrow);
        $this->entityManager->flush();
        if ($player) {
            $playerId = $player->getId();
            if (null !== $playerId) {
                $previousThrow = $this->roundThrowsRepository->findLatestForGameAndPlayer($gameId, $playerId);
                $playerScore = $previousThrow?->getScore() ?? $game->getStartScore();
                $gamePlayer = $this->gamePlayersRepository->findOneBy(['game' => $gameId, 'player' => $playerId]);
                $gamePlayer?->setScore($playerScore);
                if ($game->getWinner()?->getId() === $playerId) {
                    $game->setWinner(null);
                }
            }
        }

        // Alle Spieler-Positionen neu berechnen basierend auf aktuellen Scores
        foreach ($game->getGamePlayers() as $gamePlayer) {
            $currentPlayerScore = $gamePlayer->getScore() ?? $game->getStartScore();
            // Wenn Score > 0, dann ist der Spieler nicht fertig → Position zurücksetzen
            if ($currentPlayerScore > 0 && null !== $gamePlayer->getPosition()) {
                $gamePlayer->setPosition(0);
            }
        }

        // Wenn der letzte Wurf in einem finished Game rückgängig gemacht wird,
        // muss das Spiel wieder fortsetzbar sein.
        if (GameStatus::Finished === $game->getStatus()) {
            $game->setStatus(GameStatus::Started);
            $game->setFinishedAt(null);
        }

        foreach ($game->getGamePlayers() as $gamePlayer) {
            if ($gamePlayer->isWinner()) {
                $gamePlayer->setIsWinner(null);
            }
        }

        $latestRoundNumber = $this->roundThrowsRepository->createQueryBuilder('rt')
            ->select('MAX(r.roundNumber)')
            ->innerJoin('rt.round', 'r')
            ->andWhere('rt.game = :gameId')
            ->setParameter('gameId', $game->getGameId())
            ->getQuery()
            ->getSingleScalarResult();

        $game->setRound(
            $latestRoundNumber !== null && '' !== $latestRoundNumber
                ? (int) $latestRoundNumber
                : $lastThrowRoundNumber
        );

        foreach ($game->getGamePlayers() as $gamePlayer) {
            $gamePlayer->setIsWinner(false);
        }
        $game->setWinner(null);

        $this->entityManager->flush();
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
     * @param Game  $game
     * @param Round $currentRound
     *
     * @return void
     */
    private function maybeAdvanceRound(Game $game, Round $currentRound): void
    {
        $playersCount = $game->getGamePlayers()->count();
        if (0 === $playersCount) {
            return;
        }

        // Wir prüfen, ob alle AKTIVEN Spieler (Score > 0) 3 Würfe gemacht haben
        foreach ($game->getGamePlayers() as $gp) {
            $player = $gp->getPlayer();
            if (null === $player) {
                continue;
            }

            // Skip Spieler, die bereits gewonnen haben (Score = 0)
            $playerScore = $gp->getScore() ?? $game->getStartScore();
            if (0 === $playerScore) {
                continue;
            }

            $countForPlayer = $this->roundThrowsRepository->count([
                'round' => $currentRound,
                'player' => $player,
            ]);
            if ($countForPlayer < 3) {
                $latestThrow = $this->roundThrowsRepository->findOneBy(
                    ['round' => $currentRound, 'player' => $player],
                    ['throwNumber' => 'DESC']
                );
                if (null === $latestThrow || !$latestThrow->isBust()) {
                    return;
                    // Noch nicht alle AKTIVEN Spieler haben 3 Würfe gemacht
                }
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
        $this->entityManager->flush();
    }

    /**
     * @param Game  $game
     * @param Round $round
     * @param int   $requestedPlayerId
     *
     * @return void
     */
    private function assertActivePlayer(Game $game, Round $round, int $requestedPlayerId): void
    {
        $activePlayerId = $this->resolveActivePlayerId($game, $round);
        if (null !== $activePlayerId && $activePlayerId === $requestedPlayerId) {
            return;
        }

        throw new GamePlayerNotActiveException($requestedPlayerId, $activePlayerId);
    }

    /**
     * @param Game  $game
     * @param Round $round
     *
     * @return int|null
     */
    private function resolveActivePlayerId(Game $game, Round $round): ?int
    {
        $gamePlayers = $game->getGamePlayers()->toArray();
        usort($gamePlayers, static function (GamePlayers $left, GamePlayers $right): int {
            $leftPosition = $left->getPosition() ?? PHP_INT_MAX;
            $rightPosition = $right->getPosition() ?? PHP_INT_MAX;
            if ($leftPosition !== $rightPosition) {
                return $leftPosition <=> $rightPosition;
            }

            $leftId = $left->getGamePlayerId() ?? PHP_INT_MAX;
            $rightId = $right->getGamePlayerId() ?? PHP_INT_MAX;

            return $leftId <=> $rightId;
        });

        foreach ($gamePlayers as $gamePlayer) {
            $player = $gamePlayer->getPlayer();
            if (null === $player) {
                continue;
            }

            $playerScore = $gamePlayer->getScore() ?? $game->getStartScore();
            if (0 === $playerScore) {
                continue;
            }

            $throwsCount = $this->roundThrowsRepository->count([
                'round' => $round,
                'player' => $player,
            ]);
            if ($throwsCount >= 3) {
                continue;
            }

            $latestThrow = $this->roundThrowsRepository->findOneBy(
                ['round' => $round, 'player' => $player],
                ['throwNumber' => 'DESC']
            );
            if ($latestThrow instanceof RoundThrows && $latestThrow->isBust()) {
                continue;
            }

            return $player->getId();
        }

        return null;
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
}
