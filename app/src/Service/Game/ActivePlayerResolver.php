<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Repository\RoundThrowsRepositoryInterface;
use Override;

/**
 * Computes which player is currently active according to round/score/bust rules.
 *
 * @phpstan-type RoundStateSnapshot array<int, array{throwsCount:int,lastThrowNumber:int|null,lastThrowValue:int|null,lastThrowBust:bool}>
 *
 * @psalm-suppress UnusedClass Reason: service is auto-wired by the container and used through DI.
 */
final readonly class ActivePlayerResolver implements ActivePlayerResolverInterface
{
    /**
     * @param RoundThrowsRepositoryInterface $roundThrowsRepository
     */
    public function __construct(
        private RoundThrowsRepositoryInterface $roundThrowsRepository
    ) {
    }

    /**
     * @param Game                    $game
     * @param RoundStateSnapshot|null $roundStateSnapshot
     *
     * @return int|null
     */
    #[Override]
    public function resolveActivePlayer(Game $game, ?array $roundStateSnapshot = null): ?int
    {
        $currentRoundNumber = $game->getRound() ?? 1;
        $roundStateSnapshot ??= $this->loadCurrentRoundStateSnapshot($game, $currentRoundNumber);

        foreach ($this->sortedGamePlayers($game) as $gamePlayer) {
            $user = $gamePlayer->getPlayer();
            if (null === $user) {
                continue;
            }

            $playerId = $user->getId();
            if (null === $playerId) {
                continue;
            }

            $playerScore = $gamePlayer->getScore() ?? $game->getStartScore();
            if (0 === $playerScore) {
                continue;
            }

            $playerRoundState = TurnOrder::roundState($roundStateSnapshot, $playerId);
            $throwsCount = $playerRoundState['throwsCount'];
            $hasBusted = $playerRoundState['lastThrowBust'];

            if ($throwsCount < 3 && !$hasBusted) {
                return $playerId;
            }
        }

        return null;
    }

    /**
     * @param Game $game
     *
     * @return list<GamePlayers>
     */
    private function sortedGamePlayers(Game $game): array
    {
        /** @var list<GamePlayers> $gamePlayers */
        $gamePlayers = $game->getGamePlayers()->toArray();
        usort($gamePlayers, TurnOrder::compare(...));

        return $gamePlayers;
    }

    /**
     * @param Game $game
     * @param int  $roundNumber
     *
    * @return RoundStateSnapshot
     */
    private function loadCurrentRoundStateSnapshot(Game $game, int $roundNumber): array
    {
        $gameId = $game->getGameId();
        if (null === $gameId) {
            return [];
        }

        return $this->roundThrowsRepository->findCurrentRoundStateSnapshot($gameId, $roundNumber);
    }
}
