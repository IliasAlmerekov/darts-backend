<?php

namespace App\Service;

use App\Entity\Game;
use App\Repository\GamePlayersRepository;

class GameSetupService
{
    public function __construct(
        private readonly GamePlayersRepository $gamePlayersRepository,
    ) {
    }

    /**
     * Setzt Startscore und Position der Spieler für ein Game.
     */
    public function applyInitialScoresAndPositions(Game $game): void
    {
        $players = $this->gamePlayersRepository->findBy(
            ['gameId' => $game->getGameId()],
            ['gamePlayerId' => 'ASC']
        );

        $startScore = $game->getStartScore();

        foreach ($players as $index => $player) {
            $player->setScore($startScore);
            $player->setPosition($index + 1);
        }
    }
}
