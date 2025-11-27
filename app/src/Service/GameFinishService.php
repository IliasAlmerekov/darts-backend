<?php

namespace App\Service;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Enum\GameStatus;
use App\Repository\GamePlayersRepository;
use App\Repository\RoundThrowsRepository;
use Doctrine\ORM\EntityManagerInterface;

class GameFinishService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GamePlayersRepository $gamePlayersRepository,
        private readonly RoundThrowsRepository $roundThrowsRepository,
    )
    {
    }

    public function finishGame(Game $game, ?\DateTimeInterface $finishedAt = null): array
    {
        $game->setStatus(GameStatus::Finished);
        $game->setFinishedAt($finishedAt ?? new \DateTimeImmutable());

        $this->entityManager->flush();

        return [
            'game' => $game,
            'finishedPlayers' => $this->buildFinishedPlayersList((int) $game->getGameId()),
        ];
    }

    private function buildFinishedPlayersList(int $gameId): array
    {
        $roundsPlayedMap = $this->roundThrowsRepository->getRoundsPlayedForGame($gameId);
        $totalScoresMap = $this->roundThrowsRepository->getTotalScoreForGame($gameId);
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
            $roundsPlayed = $playerId !== null ? ($roundsPlayedMap[$playerId] ?? 0) : 0;
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
