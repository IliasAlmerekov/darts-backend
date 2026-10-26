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
use App\Exception\Game\GameFinishNotAllowedException;
use App\Exception\Game\GameNotFinishedException;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\GameRepositoryInterface;
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
     * @param GameRepositoryInterface        $gameRepository
     */
    public function __construct(private EntityManagerInterface $entityManager, private GamePlayersRepositoryInterface $gamePlayersRepository, private RoundThrowsRepositoryInterface $roundThrowsRepository, private RoundRepositoryInterface $roundRepository, private GameAccessServiceInterface $gameAccessService, private GameRepositoryInterface $gameRepository)
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
        $timestamp = $finishedAt instanceof DateTimeImmutable
            ? $finishedAt
            : (null !== $finishedAt ? DateTimeImmutable::createFromInterface($finishedAt) : new DateTimeImmutable());
        // The route loaded the game before this transaction: rank the committed scores, not that snapshot.
        return $this->entityManager->wrapInTransaction(function () use ($game, $timestamp): GameSummaryResponseDto {
            $this->gameRepository->refreshWithSeatsForUpdate($game);
            $this->gameAccessService->assertPlayerInGameOrAdmin($game);
            $status = $game->getStatus();
            if (!$status->canFinish()) {
                throw new GameFinishNotAllowedException($status);
            }

            // A repeated finish leaves the game unchanged: same finishedAt, same placements.
            if (GameStatus::Finished === $status) {
                return $this->buildGameSummary($game);
            }

            $game->setStatus(GameStatus::Finished);
            $game->setFinishedAt($timestamp);
            $this->recalculatePositions($game);

            // Built under the lock too, so a parallel undo or reopen cannot leak into the response.
            return $this->buildGameSummary($game);
        });
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
        if (GameStatus::Finished !== $game->getStatus()) {
            throw new GameNotFinishedException($game->getStatus());
        }

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
        $maxRoundNumber = ParticipantResult::maxRoundNumber(
            $finishedRounds,
            $this->roundThrowsRepository->getLastRoundNumberForGame($gameId)
        );
        $roundsPlayedMap ??= $this->roundThrowsRepository->getRoundsPlayedForGame($gameId);
        $totalScoresMap ??= $this->roundThrowsRepository->getTotalScoreForGame($gameId);
        $players = $this->gamePlayersRepository->findByGameId($gameId);
        usort($players, ParticipantResult::compareByPlacement(...));
        $result = [];
        foreach ($players as $player) {
            $user = $player->getPlayer();
            $playerId = $user?->getId();
            $playerRounds = null !== $playerId && array_key_exists($playerId, $roundsPlayedMap)
                ? $roundsPlayedMap[$playerId]
                : null;
            $roundsPlayed = ParticipantResult::roundsPlayed($player->getScore(), $playerRounds, $maxRoundNumber);
            $totalScore = null !== $playerId
                ? ($totalScoresMap[$playerId] ?? 0.0)
                : 0.0;
            $roundAverage = ParticipantResult::roundAverage((float) $totalScore, $roundsPlayed);
            $result[] = [
                'playerId' => $playerId,
                'username' => ParticipantResult::displayName($player),
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
