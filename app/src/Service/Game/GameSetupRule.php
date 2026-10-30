<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;

/**
 * Sets the initial scores and positions when a game starts.
 */
final class GameSetupRule
{
    /**
     * Sets the starting score and position of the players in a game.
     *
     * @param Game                 $game
     * @param array<int, int>|null $playerPositions
     *
     * @return void
     */
    public static function applyInitialScoresAndPositions(Game $game, ?array $playerPositions = null): void
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
            } elseif (null === $player->getPosition()) {
                $player->setPosition($index + 1);
            }
        }
    }
}
