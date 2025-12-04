<?php declare(strict_types=1);

namespace App\Service;

use App\Dto\ThrowRequest;
use App\Entity\Game;
use App\Entity\Round;
use App\Entity\RoundThrows;
use App\Enum\GameStatus;
use App\Repository\GamePlayersRepository;
use App\Repository\RoundRepository;
use App\Repository\RoundThrowsRepository;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

/**
 * Service to handle recording of game throws.
 * This class is responsible for updating the game state and recalculating the positions of the players.
 */
final readonly class GameThrowService
{
    public function __construct(
        private GamePlayersRepository  $gamePlayersRepository,
        private RoundRepository        $roundRepository,
        private RoundThrowsRepository  $roundThrowsRepository,
        private EntityManagerInterface $entityManager,
    )
    {
    }

    public function recordThrow(Game $game, ThrowRequest $dto): void
    {
        $player = $this->gamePlayersRepository->findOneBy([
            'game' => $game->getGameId(),
            'player' => $dto->playerId,
        ]);

        if ($player === null) {
            throw new InvalidArgumentException('Player not found in this game');
        }

        $round = $this->getCurrentRound($game);

        $playerThrowsThisRound = $this->roundThrowsRepository->count([
            'round' => $round,
            'player' => $player->getPlayer(),
        ]);

        if ($playerThrowsThisRound >= 3) {
            throw new InvalidArgumentException('This player has already thrown 3 times in the current round.');
        }

        $throwNumber = $playerThrowsThisRound + 1;

        $baseValue = $dto->value ?? 0;
        $finalValue = $baseValue;
        $isDouble = $dto->isDouble ?? false;
        $isTriple = $dto->isTriple ?? false;
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
        $wouldFinishGame = ($newScore === 0);

        // Hole Game-Mode Einstellungen
        $isDoubleOutMode = $game->isDoubleOut();
        $isTripleOutMode = $game->isTripleOut();

        // bust regeln
        $isBust =
            // Score unter 0
            ($newScore < 0) ||

            // Score = 1 bei Double-Out oder Triple-Out
            (($isDoubleOutMode || $isTripleOutMode) && $newScore === 1) ||

            // Score = 2 bei Triple-Out
            ($isTripleOutMode && $newScore === 2) ||

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
                    if ($throwValue !== null) {
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
            if ($newScore === 0 && $currentScore > 0) {
                $finishedPlayers = $this->gamePlayersRepository->countFinishedPlayers((int)$game->getGameId());
                $player->setPosition($finishedPlayers + 1);

                if ($finishedPlayers === 0) {
                    $game->setWinner($player->getPlayer());
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

                    if ($activePlayers === 1) {
                        $finishedPlayers = $this->gamePlayersRepository->countFinishedPlayers((int)$game->getGameId());
                        foreach ($game->getGamePlayers() as $gamePlayer) {
                            $playerScore = $gamePlayer->getScore() ?? $game->getStartScore();
                            if ($playerScore > 0 && $gamePlayer->getPosition() === null) {
                                $gamePlayer->setPosition($finishedPlayers + 1);
                            }
                        }
                    }
                }
            }
        }

        $this->entityManager->persist($roundThrow);
        $this->entityManager->flush();

        $this->maybeAdvanceRound($game, $round);
    }

    public function undoLastThrow(Game $game): void
    {
        $gameId = $game->getGameId();
        if ($gameId === null) {
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
            if ($playerId !== null) {
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
            if ($currentPlayerScore > 0 && $gamePlayer->getPosition() !== null) {
                $gamePlayer->setPosition(0);
            }
        }

        // Winner neu ermitteln: der Spieler mit Position 1 (falls vorhanden)
        $winnerPlayer = $this->gamePlayersRepository->findOneBy([
            'game' => $game->getGameId(),
            'position' => 1
        ]);

        $game->setWinner($winnerPlayer?->getPlayer());

        // Game-Status zurücksetzen, falls nötig
        if ($game->getStatus() === GameStatus::Finished) {
            $activePlayers = 0;
            foreach ($game->getGamePlayers() as $gamePlayer) {
                $playerScore = $gamePlayer->getScore() ?? $game->getStartScore();
                if ($playerScore > 0) {
                    $activePlayers++;
                }
            }

            // Wenn wieder mehr als 1 Spieler aktiv ist, Status auf Started setzen
            if ($activePlayers > 1) {
                $game->setStatus(GameStatus::Started);
                $game->setFinishedAt(null);
            }
        }

        $latestRoundNumber = $this->roundThrowsRepository->createQueryBuilder('rt')
            ->select('MAX(r.roundNumber)')
            ->innerJoin('rt.round', 'r')
            ->andWhere('rt.game = :gameId')
            ->setParameter('gameId', $game->getGameId())
            ->getQuery()
            ->getSingleScalarResult();

        $game->setRound($latestRoundNumber !== null && $latestRoundNumber !== '' ? (int)$latestRoundNumber : $lastThrowRoundNumber);

        $this->entityManager->flush();
    }

    private function getCurrentRound(Game $game): Round
    {
        $roundNumber = $game->getRound() ?? 1;

        $round = $this->roundRepository->findOneBy([
            'game' => $game,
            'roundNumber' => $roundNumber,
        ]);

        if ($round === null) {
            $round = new Round();
            $round->setRoundNumber($roundNumber);
            $round->setGame($game);
            $round->setStartedAt(new DateTime());
            $this->entityManager->persist($round);
            $game->addRound($round);
        }

        return $round;
    }

    private function maybeAdvanceRound(Game $game, Round $currentRound): void
    {
        $playersCount = $game->getGamePlayers()->count();
        if ($playersCount === 0) {
            return;
        }

        // Wir prüfen, ob alle Spieler 3 Würfe gemacht haben
        foreach ($game->getGamePlayers() as $gp) {
            $player = $gp->getPlayer();
            if ($player === null) {
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

                if ($latestThrow === null || !$latestThrow->isBust()) {
                    return; // Noch nicht alle Spieler haben 3 Würfe gemacht
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

        $this->entityManager->flush();
    }
}
