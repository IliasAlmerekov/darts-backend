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
use App\Exception\Game\GameIdMissingException;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use Override;

/**
 * This class is responsible for creating GameResponseDto objects from Game entities.
 *
 * @phpstan-type RoundStateSnapshot array<int, array{throwsCount:int,lastThrowNumber:int|null,lastThrowValue:int|null,lastThrowBust:bool}>
 *
 * @psalm-suppress UnusedClass Reason: service is auto-wired by the container and used through DI.
 */
final readonly class GameService implements GameServiceInterface
{
    /**
     * @param GamePlayersRepositoryInterface   $gamePlayersRepository
     * @param RoundThrowsRepositoryInterface   $roundThrowsRepository
     * @param ActivePlayerResolverInterface    $activePlayerResolver
     * @param GameStateVersionServiceInterface $gameStateVersionService
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        private GamePlayersRepositoryInterface $gamePlayersRepository,
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
     * @param Game                    $game               The game entity
     * @param RoundStateSnapshot|null $roundStateSnapshot Optional preloaded current-round snapshot
     *
     * @return int|null The id of the active player or null if no active player found
     */
    #[Override]
    public function calculateActivePlayer(Game $game, ?array $roundStateSnapshot = null): ?int
    {
        return $this->activePlayerResolver->resolveActivePlayer($game, $roundStateSnapshot);
    }


    /**
     * @param Game $game
     *
     * @return GameResponseDto
     */
    #[Override]
    public function createGameDto(Game $game): GameResponseDto
    {
        $gameId = $game->getGameId();
        if (null === $gameId) {
            throw new GameIdMissingException();
        }

        $currentRoundNumber = $game->getRound() ?? 1;
        $gamePlayers = $this->gamePlayersRepository->findGameStatePlayersByGameId($gameId);
        $batchedThrowData = $this->loadBatchedThrowData($gameId, $currentRoundNumber);
        $calculatedActivePlayerId = $this->resolveActivePlayerFromData($game, $gamePlayers, $batchedThrowData['currentRoundByPlayer']);

        $playerDtos = [];
        $currentThrowCountForActivePlayer = 0;
        foreach ($gamePlayers as $gamePlayer) {
            $userId = $gamePlayer['playerId'];
            $displayName = $gamePlayer['name'];

            $currentRoundThrowEntities = [];
            if (array_key_exists($userId, $batchedThrowData['currentRoundByPlayer'])) {
                $currentRoundThrowEntities = $batchedThrowData['currentRoundByPlayer'][$userId];
            }

            $isBust = false;
            $currentRoundThrows = [];
            /** @var list<array{round: int, throws: list<ThrowResponseDto>}> $roundHistory */
            $roundHistory = [];

            // Nur aktive Spieler (Score > 0) bekommen currentRoundThrows angezeigt
            $playerScore = $gamePlayer['score'] ?? $game->getStartScore();
            $isPlayerActive = ($playerScore > 0);
            $throwsThisRound = $isPlayerActive ? count($currentRoundThrowEntities) : 0;

            if ($isPlayerActive) {
                foreach ($currentRoundThrowEntities as $throw) {
                    $throwDto = $this->createThrowDto($throw);
                    if (null !== $throwDto) {
                        $currentRoundThrows[] = $throwDto;
                    }
                }

                if ($throwsThisRound > 0) {
                    $lastThrow = end($currentRoundThrowEntities);
                    if (false !== $lastThrow) {
                        $isBust = $lastThrow['isBust'];
                    }
                }
            }

            // Wenn es in der aktuellen Runde noch keine Würfe gibt (oder die Runde-Entity noch nicht existiert),
            // nehmen wir den letzten Wurf des Spielers insgesamt. Das hilft dem Client, den letzten Bust-Status
            // direkt nach einem Bust bzw. beim Round-Wechsel korrekt anzuzeigen.
            if (0 === $throwsThisRound && $isPlayerActive) {
                $latestThrowForPlayer = $batchedThrowData['latestByPlayer'][$userId] ?? null;
                if (null !== $latestThrowForPlayer) {
                    $isBust = $latestThrowForPlayer['isBust'];
                }
            }

            foreach ($batchedThrowData['historyByPlayerAndRound'][$userId] ?? [] as $roundNumber => $roundThrowEntities) {
                $throwsForRound = [];
                foreach ($roundThrowEntities as $throw) {
                    $throwDto = $this->createThrowDto($throw);
                    if (null !== $throwDto) {
                        $throwsForRound[] = $throwDto;
                    }
                }

                if (count($throwsForRound) > 0) {
                    $roundHistory[] = [
                        'round' => $roundNumber,
                        'throws' => $throwsForRound,
                    ];
                }
            }

            $isActive = ($userId === $calculatedActivePlayerId);
            if ($isActive) {
                $currentThrowCountForActivePlayer = $throwsThisRound;
            }
            $playerDtos[] = new PlayerResponseDto(
                id: $userId,
                name: $displayName,
                score: $gamePlayer['score'] ?? $game->getStartScore(),
                isActive: $isActive,
                isBust: $isBust,
                position: $gamePlayer['position'],
                throwsInCurrentRound: $throwsThisRound,
                currentRoundThrows: $currentRoundThrows,
                roundHistory: $roundHistory,
                isGuest: $gamePlayer['isGuest'],
            );
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
     * @param int $gameId
     * @param int $currentRoundNumber
     *
     * @return array{
     *     currentRoundByPlayer: array<int, list<array{playerId:int,roundNumber:int,throwNumber:int,value:int,isDouble:bool,isTriple:bool,isBust:bool}>>,
     *     historyByPlayerAndRound: array<int, array<int, list<array{playerId:int,roundNumber:int,throwNumber:int,value:int,isDouble:bool,isTriple:bool,isBust:bool}>>>,
     *     latestByPlayer: array<int, array{playerId:int,roundNumber:int,throwNumber:int,value:int,isDouble:bool,isTriple:bool,isBust:bool}>
     * }
     */
    private function loadBatchedThrowData(int $gameId, int $currentRoundNumber): array
    {
        $currentRoundByPlayer = [];
        $historyByPlayerAndRound = [];
        $latestByPlayer = [];

        foreach ($this->roundThrowsRepository->findCurrentRoundThrowsForGamePlayers($gameId, $currentRoundNumber) as $throwRow) {
            $currentRoundByPlayer[$throwRow['playerId']][] = $throwRow;
        }

        foreach ($this->roundThrowsRepository->findLatestThrowsForGamePlayers($gameId) as $throwRow) {
            $latestByPlayer[$throwRow['playerId']] = $throwRow;
        }

        foreach ($this->roundThrowsRepository->findRoundHistoryForGame($gameId) as $throwRow) {
            $historyByPlayerAndRound[$throwRow['playerId']][$throwRow['roundNumber']][] = $throwRow;
        }

        return [
            'currentRoundByPlayer' => $currentRoundByPlayer,
            'historyByPlayerAndRound' => $historyByPlayerAndRound,
            'latestByPlayer' => $latestByPlayer,
        ];
    }

    /**
        * @param Game                                                                                                                    $game
     * @param array<int, array{playerId:int,name:string,position:int|null,score:int|null,isGuest:bool}>                               $gamePlayers
     * @param array<int, list<array{playerId:int,roundNumber:int,throwNumber:int,value:int,isDouble:bool,isTriple:bool,isBust:bool}>> $currentRoundThrowsByPlayer
     *
     * @return int|null
     */
    private function resolveActivePlayerFromData(Game $game, array $gamePlayers, array $currentRoundThrowsByPlayer): ?int
    {
        foreach ($gamePlayers as $gamePlayer) {
            $playerScore = $gamePlayer['score'] ?? $game->getStartScore();
            if (0 === $playerScore) {
                continue;
            }

            $playerId = $gamePlayer['playerId'];

            $throwsInCurrentRound = $currentRoundThrowsByPlayer[$playerId] ?? [];
            $throwsCount = count($throwsInCurrentRound);
            $hasBusted = false;
            if ($throwsCount > 0) {
                $lastThrow = end($throwsInCurrentRound);
                $hasBusted = false !== $lastThrow && true === $lastThrow['isBust'];
            }

            if ($throwsCount < 3 && false === $hasBusted) {
                return $playerId;
            }
        }

        return null;
    }

    /**
     * @param array{playerId:int,roundNumber:int,throwNumber:int,value:int,isDouble:bool,isTriple:bool,isBust:bool} $throw
     *
     * @return ThrowResponseDto|null
     */
    private function createThrowDto(array $throw): ?ThrowResponseDto
    {
        $throwValue = $throw['value'];
        if (null === $throwValue) {
            return null;
        }

        return new ThrowResponseDto(
            value: $this->normalizeThrowValueForResponse((int) $throwValue, $throw['isDouble'], $throw['isTriple']),
            isDouble: $throw['isDouble'],
            isTriple: $throw['isTriple'],
            isBust: $throw['isBust'],
        );
    }

    /**
     * Normalizes stored multiplied values back to the base board segment value for API responses.
     *
     * @param int  $storedValue
     * @param bool $isDouble
     * @param bool $isTriple
     *
     * @return int
     */
    private function normalizeThrowValueForResponse(int $storedValue, bool $isDouble, bool $isTriple): int
    {
        if ($isTriple && 0 === $storedValue % 3) {
            $baseValue = intdiv($storedValue, 3);
            if ($baseValue >= 0 && $baseValue <= 20) {
                return $baseValue;
            }
        }

        if ($isDouble && 0 === $storedValue % 2) {
            $baseValue = intdiv($storedValue, 2);
            if (($baseValue >= 0 && $baseValue <= 20) || 25 === $baseValue) {
                return $baseValue;
            }
        }

        return $storedValue;
    }
}
