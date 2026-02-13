<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\GameResponseDto;
use App\Dto\PlayerResponseDto;
use App\Dto\ThrowResponseDto;
use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Exception\Game\GameIdMissingException;
use App\Repository\RoundRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use Override;

/**
 * This class is responsible for creating GameResponseDto objects from Game entities.
 *
 * @psalm-suppress UnusedClass Reason: service is auto-wired by the container and used through DI.
 */
final readonly class GameService implements GameServiceInterface
{
    /**
     * @param RoundRepositoryInterface         $roundRepository
     * @param RoundThrowsRepositoryInterface   $roundThrowsRepository
     * @param ActivePlayerResolverInterface    $activePlayerResolver
     * @param GameStateVersionServiceInterface $gameStateVersionService
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        private RoundRepositoryInterface $roundRepository,
        private RoundThrowsRepositoryInterface $roundThrowsRepository,
        private ActivePlayerResolverInterface $activePlayerResolver,
        private GameStateVersionServiceInterface $gameStateVersionService,
    ) {
    }


    /**
     * Calculates and returns the id of the active player for the given game.
     *
     * Logic:
     * Players will be sorted by their position in the game.
     * The player is active if:
     * - He has not yet reached a score of 0 (not won)
     * - He has thrown less than 3 times in the current round
     * - He is not bust in the current round
     *
     * @param Game $game The game entity
     *
     * @return int|null The id of the active player or null if no active player found
     */
    #[Override]
    public function calculateActivePlayer(Game $game): ?int
    {
        return $this->activePlayerResolver->resolveActivePlayer($game);
    }


    /**
     * @param Game $game
     *
     * @return GameResponseDto
     */
    #[Override]
    public function createGameDto(Game $game): GameResponseDto
    {
        // 1. Aktive Runde und Würfe ermitteln
        $currentRoundNumber = $game->getRound() ?? 1;
        $roundEntity = $this->roundRepository->findOneBy([
            'game' => $game,
            'roundNumber' => $currentRoundNumber,
        ]);
        // Sortiere Spieler nach Position (Reihenfolge im Spiel)
        $gamePlayers = $this->sortedGamePlayers($game);
        $calculatedActivePlayerId = $this->calculateActivePlayer($game);
        // DTOs für Spieler erstellen
        $playerDtos = [];
        $currentThrowCountForActivePlayer = 0;
        foreach ($gamePlayers as $gamePlayer) {
            $user = $gamePlayer->getPlayer();
            if (null === $user) {
                continue;
            }

            $userId = $user->getId();
            $displayName = $this->resolveGamePlayerDisplayName($gamePlayer);
            if (null === $userId || null === $displayName) {
                continue;
            }

            $throwsThisRound = 0;
            $isBust = false;
            $currentRoundThrows = [];
            /** @var list<array{round: int, throws: list<ThrowResponseDto>}> $roundHistory */
            $roundHistory = [];

            // Nur aktive Spieler (Score > 0) bekommen currentRoundThrows angezeigt
            $playerScore = $gamePlayer->getScore() ?? $game->getStartScore();
            $isPlayerActive = ($playerScore > 0);

            if ($roundEntity && $isPlayerActive) {
                $throws = $this->roundThrowsRepository->findBy([
                    'round' => $roundEntity,
                    'player' => $user,
                ], ['throwNumber' => 'ASC']);
                $throwsThisRound = count($throws);
                // Baue Array mit den einzelnen Würfen
                foreach ($throws as $throw) {
                    $throwValue = $throw->getValue();
                    if (null === $throwValue) {
                        continue;
                    }

                    $currentRoundThrows[] = new ThrowResponseDto(
                        value: $throwValue,
                        isDouble: $throw->isDouble(),
                        isTriple: $throw->isTriple(),
                        isBust: $throw->isBust(),
                    );
                }

                // Check, ob der letzte Wurf ein Bust war
                if ($throwsThisRound > 0) {
                    $lastThrow = end($throws);
                    if (false !== $lastThrow) {
                        $isBust = $lastThrow->isBust();
                    }
                }
            }

            // Wenn es in der aktuellen Runde noch keine Würfe gibt (oder die Runde-Entity noch nicht existiert),
            // nehmen wir den letzten Wurf des Spielers insgesamt. Das hilft dem Client, den letzten Bust-Status
            // direkt nach einem Bust bzw. beim Round-Wechsel korrekt anzuzeigen.
            if (0 === $throwsThisRound && $isPlayerActive) {
                $gameId = $game->getGameId();
                if (null !== $gameId) {
                    $latestThrowForPlayer = $this->roundThrowsRepository->findLatestForGameAndPlayer($gameId, $userId);
                    if ($latestThrowForPlayer instanceof \App\Entity\RoundThrows) {
                        $isBust = $latestThrowForPlayer->isBust();
                    }
                }
            }

            // Baue roundHistory: alle Runden mit Würfen für diesen Spieler
            $allRounds = $this->roundRepository->findBy(['game' => $game], ['roundNumber' => 'ASC']);
            foreach ($allRounds as $round) {
                $roundThrows = $this->roundThrowsRepository->findBy([
                    'round' => $round,
                    'player' => $user,
                ], ['throwNumber' => 'ASC']);
                if (count($roundThrows) > 0) {
                    $throws = [];
                    foreach ($roundThrows as $throw) {
                        $throwValue = $throw->getValue();
                        if (null !== $throwValue) {
                            $throws[] = new ThrowResponseDto(
                                value: $throwValue,
                                isDouble: $throw->isDouble(),
                                isTriple: $throw->isTriple(),
                                isBust: $throw->isBust(),
                            );
                        }
                    }

                    $roundNumber = $round->getRoundNumber();
                    if (null !== $roundNumber && count($throws) > 0) {
                        $roundHistory[] = [
                            'round' => $roundNumber,
                            'throws' => $throws,
                        ];
                    }
                }
            }

            $isActive = ($userId === $calculatedActivePlayerId);
            if ($isActive) {
                $currentThrowCountForActivePlayer = $throwsThisRound;
            }
            $playerDtos[] = new PlayerResponseDto(
                id: $userId,
                name: $displayName,
                score: $gamePlayer->getScore() ?? $game->getStartScore(),
                isActive: $isActive,
                isBust: $isBust,
                position: $gamePlayer->getPosition(),
                throwsInCurrentRound: $throwsThisRound,
                currentRoundThrows: $currentRoundThrows,
                roundHistory: $roundHistory,
            );
        }

        $gameId = $game->getGameId();
        if (null === $gameId) {
            throw new GameIdMissingException();
        }

        return new GameResponseDto(
            id: $gameId,
            status: $game->getStatus()->value,
            currentRound: $currentRoundNumber,
            activePlayerId: $calculatedActivePlayerId,
            currentThrowCount: $currentThrowCountForActivePlayer,
            players: $playerDtos,
            winnerId: $game->getWinner()?->getId(),
            settings: [
                'startScore' => $game->getStartScore(),
                'doubleOut' => $game->isDoubleOut(),
                'tripleOut' => $game->isTripleOut(),
            ],
        );
    }

    /**
     * @param Game $game
     *
     * @return string
     */
    #[Override]
    public function buildStateVersion(Game $game): string
    {
        return $this->gameStateVersionService->buildStateVersion($game);
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

    /**
     * @param GamePlayers $gamePlayer
     *
     * @return string|null
     */
    private function resolveGamePlayerDisplayName(GamePlayers $gamePlayer): ?string
    {
        $user = $gamePlayer->getPlayer();
        if (null === $user) {
            return null;
        }

        $baseName = $gamePlayer->getDisplayNameSnapshot();
        if (null === $baseName || '' === trim($baseName)) {
            $baseName = $user->getDisplayNameRaw() ?? $user->getUsername();
        }
        if (null === $baseName || '' === trim($baseName)) {
            return null;
        }

        return $user->isGuest()
            ? $baseName.' (Guest)'
            : $baseName;
    }
}
