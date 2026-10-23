<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\GamePlayers;

/**
 * The order in which seats take their turn, and a seat's progress in the current round.
 */
final class TurnOrder
{
    /**
     * Orders seats by position (seats without one last), then by seat id.
     *
     * @param GamePlayers $left
     * @param GamePlayers $right
     *
     * @return int
     */
    public static function compare(GamePlayers $left, GamePlayers $right): int
    {
        $leftPosition = $left->getPosition() ?? PHP_INT_MAX;
        $rightPosition = $right->getPosition() ?? PHP_INT_MAX;
        if ($leftPosition !== $rightPosition) {
            return $leftPosition <=> $rightPosition;
        }

        $leftId = $left->getGamePlayerId() ?? PHP_INT_MAX;
        $rightId = $right->getGamePlayerId() ?? PHP_INT_MAX;

        return $leftId <=> $rightId;
    }

    /**
     * @param array<int, array<string, int|bool|null>> $roundStateSnapshot
     * @param int                                      $playerId
     *
     * @return array{throwsCount:int,lastThrowNumber:int|null,lastThrowValue:int|null,lastThrowBust:bool}
     */
    public static function roundState(array $roundStateSnapshot, int $playerId): array
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
