<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\ScoreboardDeltaDto;
use App\Dto\ScoreboardPlayerDeltaDto;
use App\Dto\ThrowAckDto;
use App\Dto\ThrowDeltaDto;
use App\Dto\UndoAckDto;
use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Exception\Game\GameIdMissingException;
use App\Repository\RoundThrowsRepositoryInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Override;

/**
 * Builds compact throw acknowledgements for low-latency clients.
 *
 * @psalm-suppress UnusedClass Reason: service is auto-wired by the container and used through DI.
 */
final readonly class GameDeltaService implements GameDeltaServiceInterface
{
    /**
     * @param RoundThrowsRepositoryInterface $roundThrowsRepository
     * @param GameServiceInterface           $gameService
     */
    public function __construct(
        private RoundThrowsRepositoryInterface $roundThrowsRepository,
        private GameServiceInterface $gameService,
    ) {
    }

    /**
     * @param Game                      $game
     * @param array<string, mixed>|null $latestThrow
     *
     * @return ThrowAckDto
     */
    #[Override]
    public function buildThrowAck(Game $game, ?array $latestThrow = null): ThrowAckDto
    {
        $gameId = $game->getGameId();
        if (null === $gameId) {
            throw new GameIdMissingException();
        }

        $latestThrow ??= $this->roundThrowsRepository->findLatestForGame($gameId);
        $throwDto = $this->toThrowDelta($latestThrow);
        $stateVersion = $this->gameService->buildStateVersion($game);

        $throwPlayerId = null;
        $throwIsBust = null;
        if ($throwDto instanceof ThrowDeltaDto) {
            $throwPlayerId = $throwDto->playerId;
            $throwIsBust = $throwDto->isBust;
        }

        $activePlayerId = $this->gameService->calculateActivePlayer($game);
        $scoreboardDelta = $this->buildScoreboardDelta($game, $activePlayerId, $throwPlayerId, $throwIsBust);

        return new ThrowAckDto(
            success: true,
            gameId: $gameId,
            stateVersion: $stateVersion,
            throw: $throwDto,
            scoreboardDelta: $scoreboardDelta,
            serverTs: (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        );
    }

    /**
     * @param Game               $game
     * @param ThrowDeltaDto|null $undoneThrow
     *
     * @return UndoAckDto
     */
    #[Override]
    public function buildUndoAck(Game $game, ?ThrowDeltaDto $undoneThrow = null): UndoAckDto
    {
        $gameId = $game->getGameId();
        if (null === $gameId) {
            throw new GameIdMissingException();
        }

        $stateVersion = $this->gameService->buildStateVersion($game);
        $activePlayerId = $this->gameService->calculateActivePlayer($game);
        $scoreboardDelta = $this->buildScoreboardDelta(
            $game,
            $activePlayerId,
            $undoneThrow?->playerId,
            $this->resolveCurrentBustState($gameId, $undoneThrow?->playerId)
        );

        return new UndoAckDto(
            success: true,
            gameId: $gameId,
            stateVersion: $stateVersion,
            undoneThrow: $undoneThrow,
            scoreboardDelta: $scoreboardDelta,
            serverTs: (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        );
    }

    /**
     * @param array<string, mixed>|null $latestThrow
     *
     * @return ThrowDeltaDto|null
     */
    private function toThrowDelta(?array $latestThrow): ?ThrowDeltaDto
    {
        if (!is_array($latestThrow) || !isset($latestThrow['id'])) {
            return null;
        }

        $timestamp = $latestThrow['timestamp'] ?? null;
        if ($timestamp instanceof DateTimeInterface) {
            $timestamp = $timestamp->format(DateTimeInterface::ATOM);
        }

        return new ThrowDeltaDto(
            id: (int) ($latestThrow['id'] ?? 0),
            playerId: (int) ($latestThrow['playerId'] ?? 0),
            playerName: (string) ($latestThrow['playerName'] ?? ''),
            value: (int) ($latestThrow['value'] ?? 0),
            isDouble: (bool) ($latestThrow['isDouble'] ?? false),
            isTriple: (bool) ($latestThrow['isTriple'] ?? false),
            isBust: (bool) ($latestThrow['isBust'] ?? false),
            score: (int) ($latestThrow['score'] ?? 0),
            roundNumber: (int) ($latestThrow['roundNumber'] ?? 0),
            timestamp: is_string($timestamp) ? $timestamp : (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        );
    }

    /**
     * @param Game      $game
     * @param int|null  $activePlayerId
     * @param int|null  $highlightedPlayerId
     * @param bool|null $highlightedPlayerBustState
     *
     * @return ScoreboardDeltaDto
     */
    private function buildScoreboardDelta(Game $game, ?int $activePlayerId, ?int $highlightedPlayerId, ?bool $highlightedPlayerBustState): ScoreboardDeltaDto
    {
        return new ScoreboardDeltaDto(
            changedPlayers: $this->buildScoreboardPlayers($game, $activePlayerId, $highlightedPlayerId, $highlightedPlayerBustState),
            winnerId: $game->getWinner()?->getId(),
            status: $game->getStatus()->value,
            currentRound: $game->getRound() ?? 1,
        );
    }

    /**
     * @param Game      $game
     * @param int|null  $activePlayerId
     * @param int|null  $highlightedPlayerId
     * @param bool|null $highlightedPlayerBustState
     *
     * @return list<ScoreboardPlayerDeltaDto>
     */
    private function buildScoreboardPlayers(Game $game, ?int $activePlayerId, ?int $highlightedPlayerId, ?bool $highlightedPlayerBustState): array
    {
        $gameId = $game->getGameId();
        $currentBustStates = null;
        if (null !== $gameId) {
            $currentBustStates = $this->loadCurrentRoundBustStates($gameId, $game->getRound() ?? 1);
        }

        /** @var list<GamePlayers> $gamePlayers */
        $gamePlayers = $game->getGamePlayers()->toArray();
        usort($gamePlayers, static function (GamePlayers $left, GamePlayers $right): int {
            $leftPosition = $left->getPosition() ?? PHP_INT_MAX;
            $rightPosition = $right->getPosition() ?? PHP_INT_MAX;
            if ($leftPosition !== $rightPosition) {
                return $leftPosition <=> $rightPosition;
            }

            return ($left->getGamePlayerId() ?? PHP_INT_MAX) <=> ($right->getGamePlayerId() ?? PHP_INT_MAX);
        });

        $rows = [];
        foreach ($gamePlayers as $gamePlayer) {
            $player = $gamePlayer->getPlayer();
            $playerId = $player?->getId();
            if (null === $player || null === $playerId) {
                continue;
            }

            $name = $gamePlayer->getDisplayNameSnapshot();
            if (null === $name || '' === trim($name)) {
                $name = $player->getDisplayNameRaw() ?? $player->getUsername();
            }
            if (null === $name || '' === trim($name)) {
                continue;
            }

            $isBust = $currentBustStates[$playerId] ?? null;
            if ($playerId === $highlightedPlayerId && null !== $highlightedPlayerBustState) {
                $isBust = $highlightedPlayerBustState;
            }

            $rows[] = new ScoreboardPlayerDeltaDto(
                playerId: $playerId,
                name: $name,
                score: $gamePlayer->getScore() ?? $game->getStartScore(),
                position: $gamePlayer->getPosition(),
                isActive: $playerId === $activePlayerId,
                isGuest: $player->isGuest(),
                isBust: $isBust,
            );
        }

        return $rows;
    }

    /**
     * @param int $gameId
     * @param int $currentRound
     *
     * @return array<int, bool>
     */
    private function loadCurrentRoundBustStates(int $gameId, int $currentRound): array
    {
        $states = [];
        foreach ($this->roundThrowsRepository->findCurrentRoundThrowsForGamePlayers($gameId, $currentRound) as $throwRow) {
            $states[$throwRow['playerId']] = (bool) $throwRow['isBust'];
        }

        return $states;
    }

    /**
     * @param int      $gameId
     * @param int|null $playerId
     *
     * @return bool|null
     */
    private function resolveCurrentBustState(int $gameId, ?int $playerId): ?bool
    {
        if (null === $playerId) {
            return null;
        }

        $latestThrow = $this->roundThrowsRepository->findLatestForGame($gameId);
        if (!is_array($latestThrow)) {
            return null;
        }

        if ((int) ($latestThrow['playerId'] ?? 0) !== $playerId) {
            return null;
        }

        return (bool) ($latestThrow['isBust'] ?? false);
    }
}
