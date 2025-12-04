<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Game;

/**
 * Service to handle game setup.
 * This class is responsible for setting up the initial game state.
 */
final class GameSetupService
{
    /**
     * Setzt Startscore und Position der Spieler für ein Game.
     *
     * @param Game            $game
     * @param array<int, int>|null $playerPositions
     *
     * @return void
     */
    public function applyInitialScoresAndPositions(Game $game, ?array $playerPositions = null): void
    {
        $players = $game->getGamePlayers();
        $startScore = $game->getStartScore();
/** @var array<int, int> $positionMap */
        $positionMap = [];
        if (null !== $playerPositions) {
            foreach ($playerPositions as $index => $playerId) {
                if (is_int($index) && is_int($playerId)) {
                    $positionMap[$playerId] = $index + 1;
                }
            }
        }

        foreach ($players as $index => $player) {
            $player->setScore($startScore);
            $playerId = $player->getPlayer()?->getId();
            if (null !== $playerId && isset($positionMap[$playerId])) {
                $player->setPosition($positionMap[$playerId]);
            } else {
                $player->setPosition($index + 1);
            }
        }
    }
}
