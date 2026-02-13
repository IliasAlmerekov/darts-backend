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
        $changedPlayers = $this->buildScoreboardPlayers($game, $activePlayerId, $throwPlayerId, $throwIsBust);
        $scoreboardDelta = new ScoreboardDeltaDto(
            changedPlayers: $changedPlayers,
            winnerId: $game->getWinner()?->getId(),
            status: $game->getStatus()->value,
            currentRound: $game->getRound() ?? 1,
        );

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
     * @param int|null  $throwPlayerId
     * @param bool|null $throwIsBust
     *
     * @return list<ScoreboardPlayerDeltaDto>
     */
    private function buildScoreboardPlayers(Game $game, ?int $activePlayerId, ?int $throwPlayerId, ?bool $throwIsBust): array
    {
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

            $isBust = $playerId === $throwPlayerId ? $throwIsBust : null;
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
}
