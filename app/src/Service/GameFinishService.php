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
        $roundAverages = $this->roundThrowsRepository->getRoundAveragesForGame($gameId);
        $avgMap = [];
        foreach ($roundAverages as $row) {
            $avgMap[(int) $row['playerId']][] = [
                'roundNumber' => (int) $row['roundNumber'],
                'average' => (float) $row['average'],
            ];
        }

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
            $result[] = [
                'playerId' => $playerId,
                'username' => $user?->getUsername(),
                'position' => $player->getPosition(),
                'score' => $player->getScore(),
                'roundAverages' => $playerId !== null ? ($avgMap[$playerId] ?? []) : [],
            ];
        }

        return $result;
    }
}
