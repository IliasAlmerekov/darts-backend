<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Exception\Game\GameIdMissingException;
use App\Repository\RoundThrowsRepositoryInterface;
use DateTimeInterface;
use Override;

/**
 * Builds deterministic state hashes for game payload cache/version checks.
 */
final readonly class GameStateVersionService implements GameStateVersionServiceInterface
{
    /**
     * @param RoundThrowsRepositoryInterface $roundThrowsRepository
     */
    public function __construct(private RoundThrowsRepositoryInterface $roundThrowsRepository)
    {
    }

    /**
     * @param Game $game
     *
     * @return string
     */
    #[Override]
    public function buildStateVersion(Game $game): string
    {
        $gameId = $game->getGameId();
        if (null === $gameId) {
            throw new GameIdMissingException();
        }

        /** @var list<GamePlayers> $gamePlayers */
        $gamePlayers = $game->getGamePlayers()->toArray();
        usort($gamePlayers, static function (GamePlayers $left, GamePlayers $right): int {
            $leftPosition = $left->getPosition() ?? PHP_INT_MAX;
            $rightPosition = $right->getPosition() ?? PHP_INT_MAX;
            if ($leftPosition !== $rightPosition) {
                return $leftPosition <=> $rightPosition;
            }

            $leftId = $left->getGamePlayerId() ?? PHP_INT_MAX;
            $rightId = $right->getGamePlayerId() ?? PHP_INT_MAX;

            return $leftId <=> $rightId;
        });

        $playerChunks = [];
        foreach ($gamePlayers as $gamePlayer) {
            $isWinner = $gamePlayer->isWinner();
            $playerChunks[] = implode(':', [
                (string) ($gamePlayer->getPlayer()?->getId() ?? 'n'),
                (string) ($gamePlayer->getPosition() ?? 'n'),
                (string) ($gamePlayer->getScore() ?? 'n'),
                null === $isWinner ? 'n' : ($isWinner ? '1' : '0'),
            ]);
        }

        $latestThrowId = $this->roundThrowsRepository
            ->findEntityLatestForGame($gameId)
            ?->getThrowId();

        $payload = [
            'gameId' => $gameId,
            'status' => $game->getStatus()->value,
            'round' => $game->getRound(),
            'winnerId' => $game->getWinner()?->getId(),
            'startScore' => $game->getStartScore(),
            'doubleOut' => $game->isDoubleOut(),
            'tripleOut' => $game->isTripleOut(),
            'finishedAt' => $game->getFinishedAt()?->format(DateTimeInterface::ATOM),
            'latestThrowId' => $latestThrowId,
            'players' => $playerChunks,
        ];

        $encodedPayload = json_encode($payload);
        if (false === $encodedPayload) {
            $encodedPayload = serialize($payload);
        }

        return hash('sha256', $encodedPayload);
    }
}
