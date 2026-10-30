<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\GameResponseDto;
use App\Dto\ScoreboardDeltaDto;
use App\Dto\ScoreboardPlayerDeltaDto;
use App\Dto\ThrowAckDto;
use App\Dto\ThrowDeltaDto;
use App\Dto\UndoAckDto;
use App\Entity\RoundThrows;
use DateTimeImmutable;
use DateTimeInterface;
use App\Dto\PlayerResponseDto;
use App\Dto\ThrowResponseDto;
use App\Entity\Game;
use App\Enum\GameStatus;
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
final readonly class GameStateReader implements GameStateReaderInterface
{
    /**
     * @param GamePlayersRepositoryInterface   $gamePlayersRepository
     * @param RoundThrowsRepositoryInterface   $roundThrowsRepository
     * @param GameStateVersionServiceInterface $gameStateVersionService
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        private GamePlayersRepositoryInterface $gamePlayersRepository,
        private RoundThrowsRepositoryInterface $roundThrowsRepository,
        private GameStateVersionServiceInterface $gameStateVersionService,
    ) {
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

            // Only active players (score > 0) see currentRoundThrows
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

            // If the current round has no throws or its entity does not exist yet, use the player's
            // last throw overall. This lets the client show the last bust status after a bust
            // or a round change.
            if (0 === $throwsThisRound && $isPlayerActive && $userId !== $calculatedActivePlayerId) {
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
                placement: $gamePlayer['placement'],
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
     * @param Game     $game
     * @param int|null $latestThrowId
     *
     * @return string
     */
    #[Override]
    public function stateVersion(Game $game, ?int $latestThrowId = null): string
    {
        return $this->gameStateVersionService->buildStateVersion($game, $latestThrowId);
    }

    /** @param Game             $game
     * @param RoundThrows|null $throw
     *
     * @return ThrowAckDto
     */
    #[Override]
    public function buildThrowAck(Game $game, ?RoundThrows $throw): ThrowAckDto
    {
        $state = $this->createGameDto($game);

        return new ThrowAckDto(
            success: true,
            gameId: $state->id,
            stateVersion: $this->stateVersion($game, $throw?->getThrowId()),
            throw: $this->throwDelta($throw, $state),
            scoreboardDelta: $this->scoreboard($state),
            serverTs: (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        );
    }

    /** @param Game             $game
     * @param RoundThrows|null $throw
     *
     * @return UndoAckDto
     */
    #[Override]
    public function buildUndoAck(Game $game, ?RoundThrows $throw): UndoAckDto
    {
        $state = $this->createGameDto($game);

        return new UndoAckDto(
            success: true,
            gameId: $state->id,
            stateVersion: $this->stateVersion($game),
            undoneThrow: $this->throwDelta($throw, $state),
            scoreboardDelta: $this->scoreboard($state),
            serverTs: (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        );
    }

    /** @param GameResponseDto $state
     *
     * @return ScoreboardDeltaDto
     */
    private function scoreboard(GameResponseDto $state): ScoreboardDeltaDto
    {
        $players = [];
        foreach ($state->players as $player) {
            $players[] = new ScoreboardPlayerDeltaDto(
                playerId: $player->id,
                name: $player->name,
                score: $player->score,
                position: $player->position,
                placement: $player->placement,
                isActive: $player->isActive,
                isGuest: $player->isGuest,
                isBust: $player->isBust,
            );
        }

        return new ScoreboardDeltaDto(changedPlayers: $players, winnerId: $state->winnerId, status: $state->status, currentRound: $state->currentRound);
    }

    /** @param RoundThrows|null $throw
     * @param GameResponseDto  $state
     *
     * @return ThrowDeltaDto|null
     */
    private function throwDelta(?RoundThrows $throw, GameResponseDto $state): ?ThrowDeltaDto
    {
        if (null === $throw) {
            return null;
        }
        $player = $throw->getPlayer();
        $playerId = $player?->getId() ?? 0;
        $name = null !== $player ? PlayerDisplayName::forUser($player) : '';
        foreach ($state->players as $seat) {
            if ($seat->id === $playerId) {
                $name = $seat->name;
                break;
            }
        }
        $timestamp = $throw->getTimestamp();

        return new ThrowDeltaDto(
            id: $throw->getThrowId() ?? 0,
            playerId: $playerId,
            playerName: $name,
            value: DartThrow::baseValue($throw->getValue() ?? 0, $throw->isDouble(), $throw->isTriple()),
            isDouble: $throw->isDouble(),
            isTriple: $throw->isTriple(),
            isBust: $throw->isBust(),
            score: $throw->getScore() ?? 0,
            roundNumber: $throw->getRound()?->getRoundNumber() ?? 0,
            timestamp: $timestamp instanceof DateTimeInterface ? $timestamp->format(DateTimeInterface::ATOM) : (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        );
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

        foreach ($this->roundThrowsRepository->findRoundHistoryForGame($gameId) as $throwRow) {
            $playerId = $throwRow['playerId'];
            $roundNumber = $throwRow['roundNumber'];

            $historyByPlayerAndRound[$playerId][$roundNumber][] = $throwRow;
            $latestByPlayer[$playerId] = $throwRow;

            if ($roundNumber === $currentRoundNumber) {
                $currentRoundByPlayer[$playerId][] = $throwRow;
            }
        }

        return [
            'currentRoundByPlayer' => $currentRoundByPlayer,
            'historyByPlayerAndRound' => $historyByPlayerAndRound,
            'latestByPlayer' => $latestByPlayer,
        ];
    }

    /**
     * @param Game                                                                                                                    $game
     * @param array<int, array{playerId:int,name:string,position:int|null,placement:int|null,score:int|null,isGuest:bool}>            $gamePlayers
     * @param array<int, list<array{playerId:int,roundNumber:int,throwNumber:int,value:int,isDouble:bool,isTriple:bool,isBust:bool}>> $currentRoundThrowsByPlayer
     *
     * @return int|null
     */
    private function resolveActivePlayerFromData(Game $game, array $gamePlayers, array $currentRoundThrowsByPlayer): ?int
    {
        if (GameStatus::Started !== $game->getStatus()) {
            return null;
        }
        $snapshot = [];
        foreach ($currentRoundThrowsByPlayer as $playerId => $throws) {
            foreach ($throws as $throw) {
                $snapshot = TurnOrder::recordThrow($snapshot, $playerId, $throw['throwNumber'], $throw['value'], $throw['isBust']);
            }
        }
        $seats = [];
        foreach ($gamePlayers as $seat) {
            $seats[] = ['playerId' => $seat['playerId'], 'score' => $seat['score'] ?? $game->getStartScore()];
        }

        return TurnOrder::activePlayerIdFromSeats($seats, $snapshot);
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
            value: DartThrow::baseValue((int) $throwValue, $throw['isDouble'], $throw['isTriple']),
            isDouble: $throw['isDouble'],
            isTriple: $throw['isTriple'],
            isBust: $throw['isBust'],
        );
    }
}
