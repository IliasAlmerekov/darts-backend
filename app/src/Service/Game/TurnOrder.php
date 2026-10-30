<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
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
    /**
     * @param list<array{playerId:int,score:int}>      $seats
     * @param array<int, array<string, int|bool|null>> $snapshot
     *
     * @return int|null
     */
    public static function activePlayerIdFromSeats(array $seats, array $snapshot): ?int
    {
        foreach ($seats as $seat) {
            $state = self::roundState($snapshot, $seat['playerId']);
            if (0 !== $seat['score'] && $state['throwsCount'] < 3 && !$state['lastThrowBust']) {
                return $seat['playerId'];
            }
        }

        return null;
    }

    /**
     * @param Game                                     $game
     * @param array<int, array<string, int|bool|null>> $snapshot
     *
     * @return int|null
     */
    public static function activePlayerId(Game $game, array $snapshot): ?int
    {
        $players = $game->getGamePlayers()->toArray();
        usort($players, self::compare(...));
        $seats = [];
        foreach ($players as $player) {
            $id = $player->getPlayer()?->getId();
            if (null !== $id) {
                $seats[] = ['playerId' => $id, 'score' => $player->getScore() ?? $game->getStartScore()];
            }
        }

        return self::activePlayerIdFromSeats($seats, $snapshot);
    }

    /**
     * @param Game                                     $game
     * @param array<int, array<string, int|bool|null>> $snapshot
     *
     * @return bool
     */
    public static function isRoundComplete(Game $game, array $snapshot): bool
    {
        return !$game->getGamePlayers()->isEmpty() && null === self::activePlayerId($game, $snapshot);
    }

    /**
     * @param array<int, array{throwsCount:int,lastThrowNumber:int|null,lastThrowValue:int|null,lastThrowBust:bool}> $snapshot
     * @param int                                                                                                    $playerId
     * @param int                                                                                                    $throwNumber
     * @param int                                                                                                    $value
     * @param bool                                                                                                   $isBust
     *
     * @return array<int, array{throwsCount:int,lastThrowNumber:int|null,lastThrowValue:int|null,lastThrowBust:bool}>
     */
    public static function recordThrow(array $snapshot, int $playerId, int $throwNumber, int $value, bool $isBust): array
    {
        $snapshot[$playerId] = [
            'throwsCount' => self::roundState($snapshot, $playerId)['throwsCount'] + 1,
            'lastThrowNumber' => $throwNumber,
            'lastThrowValue' => $value,
            'lastThrowBust' => $isBust,
        ];

        return $snapshot;
    }
}
