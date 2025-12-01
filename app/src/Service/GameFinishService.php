<?php

namespace App\Service;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Enum\GameStatus;
use App\Repository\GamePlayersRepository;
use App\Repository\RoundRepository;
use App\Repository\RoundThrowsRepository;
use Doctrine\ORM\EntityManagerInterface;

class GameFinishService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GamePlayersRepository $gamePlayersRepository,
        private readonly RoundThrowsRepository $roundThrowsRepository,
        private readonly RoundRepository $roundRepository,
    )
    {
    }

    public function finishGame(Game $game, ?\DateTimeInterface $finishedAt = null): array
    {
        $game->setStatus(GameStatus::Finished);
        $game->setFinishedAt($finishedAt ?? new \DateTimeImmutable());

        $this->recalculatePositions($game);

        $this->entityManager->flush();

        $finishedRounds = $this->roundRepository->countFinishedRounds((int) $game->getGameId());

        return $this->buildFinishedPlayersList((int) $game->getGameId(), $finishedRounds);
    }

    private function recalculatePositions(Game $game): void
    {
        $players = $this->gamePlayersRepository->findByGameId((int) $game->getGameId());

        usort($players, static function (GamePlayers $a, GamePlayers $b): int {
            $scoreA = $a->getScore() ?? PHP_INT_MAX;
            $scoreB = $b->getScore() ?? PHP_INT_MAX;

            if ($scoreA === $scoreB) {
                return ($a->getPosition() ?? PHP_INT_MAX) <=> ($b->getPosition() ?? PHP_INT_MAX);
            }

            return $scoreA <=> $scoreB;
        });

        foreach ($players as $index => $player) {
            $player->setPosition($index + 1);
        }
    }

    public function getGameStats(Game $game): array
    {
        $gameId = (int) $game->getGameId();
        $finishedRounds = $this->roundRepository->countFinishedRounds($gameId);
        $roundsPlayedMap = $this->roundThrowsRepository->getRoundsPlayedForGame($gameId);
        $totalScoresMap = $this->roundThrowsRepository->getTotalScoreForGame($gameId);

        $winner = $game->getWinner();
        $winnerId = $winner?->getId();
        $winnerRounds = $winnerId !== null ? max($roundsPlayedMap[$winnerId] ?? 0, $finishedRounds) : 0;
        $winnerTotal = $winnerId !== null ? ($totalScoresMap[$winnerId] ?? 0.0) : 0.0;
        $winnerAverage = $winnerRounds > 0 ? $winnerTotal / $winnerRounds : 0.0;

        return [
            'gameId' => $gameId,
            'date' => $game->getDate(),
            'finishedAt' => $game->getFinishedAt(),
            'winner' => $winner ? [
                'id' => $winner->getId(),
                'username' => $winner->getUsername(),
            ] : null,
            'winnerRoundsPlayed' => $winnerRounds,
            'winnerRoundAverage' => $winnerAverage,
            'finishedPlayers' => $this->buildFinishedPlayersList($gameId, $finishedRounds, $roundsPlayedMap, $totalScoresMap),
        ];
    }

    private function buildFinishedPlayersList(int $gameId, int $finishedRounds, ?array $roundsPlayedMap = null, ?array $totalScoresMap = null): array
    {
        $roundsPlayedMap ??= $this->roundThrowsRepository->getRoundsPlayedForGame($gameId);
        $totalScoresMap ??= $this->roundThrowsRepository->getTotalScoreForGame($gameId);
        $players = $this->gamePlayersRepository->findByGameId($gameId);

        usort($players, static function (GamePlayers $a, GamePlayers $b): int {
            $posA = $a->getPosition();
            $posB = $b->getPosition();

            if ($posA === null && $posB === null) {
                return ($a->getScore() ?? PHP_INT_MAX) <=> ($b->getScore() ?? PHP_INT_MAX);
            }

            if ($posA === null) {
                return 1;
            }

            if ($posB === null) {
                return -1;
            }

            return $posA <=> $posB;
        });

        $result = [];
        foreach ($players as $player) {
            $user = $player->getPlayer();
            $playerId = $user?->getId();
            $roundsPlayed = $playerId !== null ? max($roundsPlayedMap[$playerId] ?? 0, $finishedRounds) : $finishedRounds;
            $totalScore = $playerId !== null ? ($totalScoresMap[$playerId] ?? 0.0) : 0.0;
            $roundAverage = $roundsPlayed > 0 ? $totalScore / $roundsPlayed : 0.0;

            $result[] = [
                'playerId' => $playerId,
                'username' => $user?->getUsername(),
                'position' => $player->getPosition(),
                'roundsPlayed' => $roundsPlayed,
                'roundAverage' => $roundAverage,
            ];
        }

        return $result;
    }
}
