<?php

namespace App\Service;

use App\Entity\Game;

class GameSetupService
{
    /**
     * Setzt Startscore und Position der Spieler für ein Game.
     */
    public function applyInitialScoresAndPositions(Game $game, ?array $playerPositions = null): void
    {
        $players = $game->getGamePlayers();

        $startScore = $game->getStartScore();

        $positionMap = [];
        if ($playerPositions !== null) {
            foreach ($playerPositions as $index => $playerId) {
                $positionMap[(int) $playerId] = $index + 1;
            }
        }

        foreach ($players as $index => $player) {
            $player->setScore($startScore);

            $playerId = $player->getPlayer()?->getId();
            if ($playerId !== null && isset($positionMap[$playerId])) {
                $player->setPosition($positionMap[$playerId]);
            } else {
                $player->setPosition($index + 1);
            }
        }
    }
}
