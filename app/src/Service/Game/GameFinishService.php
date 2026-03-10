<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\GameSummaryFinishedPlayerDto;
use App\Dto\GameSummaryResponseDto;
use App\Dto\GameSummaryWinnerDto;
use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Enum\GameStatus;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\RoundRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Security\GameAccessServiceInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service to handle finishing games.
 * This class is responsible for updating the game status and recalculating the positions of the players.
 */
final readonly class GameFinishService implements GameFinishServiceInterface
{
    /**
     * @param EntityManagerInterface         $entityManager
     * @param GamePlayersRepositoryInterface $gamePlayersRepository
     * @param RoundThrowsRepositoryInterface $roundThrowsRepository
     * @param RoundRepositoryInterface       $roundRepository
     * @param GameAccessServiceInterface     $gameAccessService
     */
    public function __construct(private EntityManagerInterface $entityManager, private GamePlayersRepositoryInterface $gamePlayersRepository, private RoundThrowsRepositoryInterface $roundThrowsRepository, private RoundRepositoryInterface $roundRepository, private GameAccessServiceInterface $gameAccessService)
    {
    }

    /**
     * @param Game                   $game
     * @param DateTimeInterface|null $finishedAt
     *
     * @return GameSummaryResponseDto
     */
    #[\Override]
    public function finishGame(Game $game, ?DateTimeInterface $finishedAt = null): GameSummaryResponseDto
    {
        $this->gameAccessService->assertPlayerInGameOrAdmin($game);
        $game->setStatus(GameStatus::Finished);
        $timestamp = $finishedAt instanceof DateTimeImmutable
            ? $finishedAt
            : (null !== $finishedAt ? DateTimeImmutable::createFromInterface($finishedAt) : new DateTimeImmutable());
        $game->setFinishedAt($timestamp);
        $this->recalculatePositions($game);
        $this->entityManager->flush();

        return $this->buildGameSummary($game);
    }

    /**
     * @param Game $game
     *
     * @return GameSummaryResponseDto
     */
    #[\Override]
    public function getGameStats(Game $game): GameSummaryResponseDto
    {
        return $this->buildGameSummary($game);
    }

    /**
     * @param Game $game
     *
     * @return GameSummaryResponseDto
     */
    #[\Override]
    public function getGameSummary(Game $game): GameSummaryResponseDto
    {
        $this->gameAccessService->assertPlayerInGameOrAdmin($game);

        return $this->buildGameSummary($game);
    }

    /**
     * @param int                    $gameId
     * @param int|null               $finishedRounds
     * @param array<int, int>|null   $roundsPlayedMap
     * @param array<int, float>|null $totalScoresMap
     *
     * @return list<array{
     *     playerId:int|null,
     *     username:string|null,
     *     position:int|null,
     *     roundsPlayed:int|null,
     *     roundAverage:float
     * }>
     */
    #[\Override]
    public function buildFinishedPlayersList(int $gameId, ?int $finishedRounds = null, ?array $roundsPlayedMap = null, ?array $totalScoresMap = null): array
    {
        $lastRoundsMap = $this->roundThrowsRepository->getLastRoundNumberForGame($gameId);
        $maxRoundNumber = $finishedRounds;
        if ([] !== $lastRoundsMap) {
            $maxRoundNumber = max($maxRoundNumber, max($lastRoundsMap));
        }
        $roundsPlayedMap ??= $this->roundThrowsRepository->getRoundsPlayedForGame($gameId);
        $totalScoresMap ??= $this->roundThrowsRepository->getTotalScoreForGame($gameId);
        $players = $this->gamePlayersRepository->findByGameId($gameId);
        usort($players, static function (GamePlayers $a, GamePlayers $b): int {
            $posA = $a->getPosition();
            $posB = $b->getPosition();
            if (null === $posA && null === $posB) {
                return ($a->getScore() ?? PHP_INT_MAX) <=> ($b->getScore() ?? PHP_INT_MAX);
            }

            if (null === $posA) {
                return 1;
            }

            if (null === $posB) {
                return -1;
            }

            return $posA <=> $posB;
        });
        $result = [];
        foreach ($players as $player) {
            $user = $player->getPlayer();
            $playerId = $user?->getId();
            $score = $player->getScore();
            $playerRounds = null !== $playerId && array_key_exists($playerId, $roundsPlayedMap)
                ? $roundsPlayedMap[$playerId]
                : null;
            if (null !== $score && 0 === $score) {
                $roundsPlayed = $playerRounds ?? 0;
            } else {
                $roundsPlayed = $maxRoundNumber;
            }
            $totalScore = null !== $playerId
                ? ($totalScoresMap[$playerId] ?? 0.0)
                : 0.0;
            $roundAverage = $roundsPlayed > 0 ? (float) $totalScore / (float) $roundsPlayed : 0.0;
            $result[] = [
                'playerId' => $playerId,
                'username' => $this->resolveGamePlayerDisplayName($player),
                'position' => $player->getPosition(),
                'roundsPlayed' => $roundsPlayed,
                'roundAverage' => $roundAverage,
            ];
        }

        return $result;
    }

    /**
     * @param Game $game
     *
     * @return void
     */
    private function recalculatePositions(Game $game): void
    {
        $players = $this->gamePlayersRepository->findByGameId((int) $game->getGameId());

        usort($players, static function (GamePlayers $a, GamePlayers $b): int {
            $scoreA = $a->getScore() ?? PHP_INT_MAX;
            $scoreB = $b->getScore() ?? PHP_INT_MAX;
            if ($scoreA === $scoreB) {
                return ($a->getPosition() ?? PHP_INT_MAX) <=> ($b->getPosition() ?? PHP_INT_MAX);
            }

            return $scoreA <=> $scoreB;
        });
        foreach ($players as $index => $player) {
            $player->setPosition($index + 1);
        }

        if ([] !== $players) {
            $winnerPlayer = $players[0];
            $game->setWinner($winnerPlayer->getPlayer());
            foreach ($players as $player) {
                $player->setIsWinner($player === $winnerPlayer);
            }
        }
    }

    /**
     * @param GamePlayers $player
     *
     * @return string|null
     */
    private function resolveGamePlayerDisplayName(GamePlayers $player): ?string
    {
        $user = $player->getPlayer();
        if (null === $user) {
            return null;
        }

        $baseName = $player->getDisplayNameSnapshot();
        if (null === $baseName || '' === trim($baseName)) {
            $baseName = $user->getDisplayNameRaw() ?? $user->getUsername();
        }
        if (null === $baseName || '' === trim($baseName)) {
            return null;
        }

        return $baseName;
    }

    /**
     * @param Game $game
     *
     * @return GameSummaryResponseDto
     */
    private function buildGameSummary(Game $game): GameSummaryResponseDto
    {
        $gameId = (int) $game->getGameId();
        $finishedRounds = $this->roundRepository->countFinishedRounds($gameId);
        $roundsPlayedMap = $this->roundThrowsRepository->getRoundsPlayedForGame($gameId);
        $totalScoresMap = $this->roundThrowsRepository->getTotalScoreForGame($gameId);

        return $this->createGameSummaryResponseDto($game, $finishedRounds, $roundsPlayedMap, $totalScoresMap);
    }

    /**
        * @param Game              $game
        * @param int|null          $finishedRounds
        * @param array<int, int>   $roundsPlayedMap
        * @param array<int, float> $totalScoresMap
     *
     * @return GameSummaryResponseDto
     */
    private function createGameSummaryResponseDto(Game $game, ?int $finishedRounds, array $roundsPlayedMap, array $totalScoresMap): GameSummaryResponseDto
    {
        $gameId = (int) $game->getGameId();
        $finishedPlayers = $this->buildFinishedPlayersList($gameId, $finishedRounds, $roundsPlayedMap, $totalScoresMap);
        $winner = $this->resolveWinner($game, $gameId);
        $winnerId = $winner?->getId();
        $winnerRounds = 0;
        $winnerName = null;

        if (null !== $winnerId) {
            foreach ($finishedPlayers as $finishedPlayer) {
                if ($finishedPlayer['playerId'] === $winnerId) {
                    $winnerRounds = $finishedPlayer['roundsPlayed'] ?? 0;
                    $winnerName = $finishedPlayer['username'];

                    break;
                }
            }
        }

        $winnerTotal = null !== $winnerId ? ($totalScoresMap[$winnerId] ?? 0.0) : 0.0;
        $winnerAverage = $winnerRounds > 0 ? (float) $winnerTotal / (float) $winnerRounds : 0.0;

        return new GameSummaryResponseDto(
            gameId: $gameId,
            finishedAt: $game->getFinishedAt()?->format(DateTimeInterface::ATOM),
            winner: null !== $winnerId ? new GameSummaryWinnerDto($winnerId, $winnerName) : null,
            winnerRoundsPlayed: $winnerRounds,
            winnerRoundAverage: $winnerAverage,
            finishedPlayers: $this->createFinishedPlayerDtos($finishedPlayers),
        );
    }

    /**
     * @param Game $game
     * @param int  $gameId
     *
     * @return \App\Entity\User|null
     */
    private function resolveWinner(Game $game, int $gameId): ?\App\Entity\User
    {
        $winner = $game->getWinner();
        if (null !== $winner) {
            return $winner;
        }

        $players = $this->gamePlayersRepository->findByGameId($gameId);
        foreach ($players as $player) {
            if (true === $player->isWinner()) {
                return $player->getPlayer();
            }
        }

        return null;
    }

    /**
     * @param array $finishedPlayers
     *
     * @return list<GameSummaryFinishedPlayerDto>
     */
    private function createFinishedPlayerDtos(array $finishedPlayers): array
    {
        $result = [];
        foreach ($finishedPlayers as $finishedPlayer) {
            $result[] = new GameSummaryFinishedPlayerDto(
                playerId: $finishedPlayer['playerId'],
                username: $finishedPlayer['username'],
                position: $finishedPlayer['position'],
                roundsPlayed: $finishedPlayer['roundsPlayed'],
                roundAverage: $finishedPlayer['roundAverage'],
            );
        }

        return $result;
    }
}
