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
use App\Enum\GameStatus;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\RoundRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
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
     */
    public function __construct(private EntityManagerInterface $entityManager, private GamePlayersRepositoryInterface $gamePlayersRepository, private RoundThrowsRepositoryInterface $roundThrowsRepository, private RoundRepositoryInterface $roundRepository)
    {
    }

    /**
     * @param Game                   $game
     * @param DateTimeInterface|null $finishedAt
     *
     * @return array
     */
    #[\Override]
    public function finishGame(Game $game, ?DateTimeInterface $finishedAt = null): array
    {
        $game->setStatus(GameStatus::Finished);
        $timestamp = $finishedAt instanceof DateTimeImmutable
            ? $finishedAt
            : (null !== $finishedAt ? DateTimeImmutable::createFromInterface($finishedAt) : new DateTimeImmutable());
        $game->setFinishedAt($timestamp);
        $this->recalculatePositions($game);
        $this->entityManager->flush();
        $finishedRounds = $this->roundRepository->countFinishedRounds((int) $game->getGameId());

        return $this->buildFinishedPlayersList((int) $game->getGameId(), $finishedRounds);
    }

    /**
     * @param Game $game
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function getGameStats(Game $game): array
    {
        $gameId = (int) $game->getGameId();
        $finishedRounds = $this->roundRepository->countFinishedRounds($gameId);
        $roundsPlayedMap = $this->roundThrowsRepository->getRoundsPlayedForGame($gameId);
        $totalScoresMap = $this->roundThrowsRepository->getTotalScoreForGame($gameId);
        $winner = $game->getWinner();
        if (null === $winner) {
            $players = $this->gamePlayersRepository->findByGameId($gameId);
            foreach ($players as $player) {
                if (true === $player->isWinner()) {
                    $winner = $player->getPlayer();
                    $game->setWinner($winner);
                    break;
                }
            }
        }
        $winnerId = $winner?->getId();
        $finishedPlayers = $this->buildFinishedPlayersList(
            $gameId,
            $finishedRounds,
            $roundsPlayedMap,
            $totalScoresMap
        );
        $winnerRounds = 0;
        if (null !== $winnerId) {
            foreach ($finishedPlayers as $fp) {
                if ($fp['playerId'] === $winnerId) {
                    $winnerRounds = $fp['roundsPlayed'];
                    break;
                }
            }
        }
        $winnerTotal = null !== $winnerId ? ($totalScoresMap[$winnerId] ?? 0.0) : 0.0;
        $winnerAverage = $winnerRounds > 0 ? (float) $winnerTotal / (float) $winnerRounds : 0.0;

        return [
            'gameId' => $gameId,
            'date' => $game->getDate(),
            'finishedAt' => $game->getFinishedAt(),
            'winner' => $winner
                ? [
                    'id' => $winner->getId(),
                    'username' => $winner->getUsername(),
                ]
                : null,
            'winnerRoundsPlayed' => $winnerRounds,
            'winnerRoundAverage' => $winnerAverage,
            'finishedPlayers' => $finishedPlayers,
        ];
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
                'username' => $user?->getUsername(),
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
}
