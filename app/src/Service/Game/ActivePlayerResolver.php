<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\RoundThrows;
use App\Repository\RoundRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use Override;

/**
 * Computes which player is currently active according to round/score/bust rules.
 *
 * @psalm-suppress UnusedClass Reason: service is auto-wired by the container and used through DI.
 */
final readonly class ActivePlayerResolver implements ActivePlayerResolverInterface
{
    /**
     * @param RoundRepositoryInterface       $roundRepository
     * @param RoundThrowsRepositoryInterface $roundThrowsRepository
     */
    public function __construct(
        private RoundRepositoryInterface $roundRepository,
        private RoundThrowsRepositoryInterface $roundThrowsRepository
    ) {
    }

    /**
     * @param Game $game
     *
     * @return int|null
     */
    #[Override]
    public function resolveActivePlayer(Game $game): ?int
    {
        $currentRoundNumber = $game->getRound() ?? 1;
        $roundEntity = $this->roundRepository->findOneBy([
            'game' => $game,
            'roundNumber' => $currentRoundNumber,
        ]);

        foreach ($this->sortedGamePlayers($game) as $gamePlayer) {
            $user = $gamePlayer->getPlayer();
            if (null === $user) {
                continue;
            }

            $playerScore = $gamePlayer->getScore() ?? $game->getStartScore();
            if (0 === $playerScore) {
                continue;
            }

            $throwsCount = 0;
            $hasBusted = false;
            if (null !== $roundEntity) {
                $throwsCount = $this->roundThrowsRepository->count([
                    'round' => $roundEntity,
                    'player' => $user,
                ]);
                if ($throwsCount > 0) {
                    $lastThrow = $this->roundThrowsRepository->findOneBy(
                        ['round' => $roundEntity, 'player' => $user],
                        ['throwNumber' => 'DESC']
                    );
                    $hasBusted = $lastThrow instanceof RoundThrows && $lastThrow->isBust();
                }
            }

            if ($throwsCount < 3 && !$hasBusted) {
                return $user->getId();
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

        return $gamePlayers;
    }
}
