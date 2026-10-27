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
     * @param GameAccessServiceInterface     $gameAccessService
     * @param GameRepositoryInterface        $gameRepository
     */
    public function __construct(private EntityManagerInterface $entityManager, private GamePlayersRepositoryInterface $gamePlayersRepository, private RoundThrowsRepositoryInterface $roundThrowsRepository, private GameAccessServiceInterface $gameAccessService, private GameRepositoryInterface $gameRepository)
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
            $this->recalculatePlacements($game);

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
     * @param int $gameId
     *
     * @return list<array{
     *     playerId:int|null,
     *     username:string|null,
     *     position:int|null,
     *     placement:int|null,
     *     roundsPlayed:int,
     *     roundAverage:float
     * }>
     */
    #[\Override]
    public function buildFinishedPlayersList(int $gameId): array
    {
        $totals = $this->roundThrowsRepository->getParticipantTotalsForGames([$gameId])[$gameId] ?? [];
        $players = $this->gamePlayersRepository->findByGameId($gameId);
        usort($players, ParticipantResult::compareByPlacement(...));
        $result = [];
        foreach ($players as $player) {
            $user = $player->getPlayer();
            $playerId = $user?->getId();
            $ownTotals = null !== $playerId ? ($totals[$playerId] ?? null) : null;
            $result[] = [
                'playerId' => $playerId,
                'username' => ParticipantResult::displayName($player),
                'position' => $player->getPosition(),
                'placement' => $player->getPlacement(),
                'roundsPlayed' => $ownTotals?->turns ?? 0,
                'roundAverage' => ParticipantResult::threeDartAverage($ownTotals),
            ];
        }

        return $result;
    }

    /**
     * @param Game $game
     *
     * @return void
     */
    private function recalculatePlacements(Game $game): void
    {
        $players = $this->gamePlayersRepository->findByGameId((int) $game->getGameId());

        usort($players, static function (GamePlayers $a, GamePlayers $b): int {
            $scoreA = $a->getScore() ?? PHP_INT_MAX;
            $scoreB = $b->getScore() ?? PHP_INT_MAX;
            if ($scoreA === $scoreB) {
                // Seats that checked out keep their order, then the turn order breaks the tie.
                return ParticipantResult::compareByPlacement($a, $b) ?: TurnOrder::compare($a, $b);
            }

            return $scoreA <=> $scoreB;
        });
        foreach ($players as $index => $player) {
            $player->setPlacement($index + 1);
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
        $finishedPlayers = $this->buildFinishedPlayersList($gameId);
        $winner = $this->resolveWinner($game, $gameId);
        $winnerId = $winner?->getId();
        $winnerRounds = 0;
        $winnerAverage = 0.0;
        $winnerName = null;

        if (null !== $winnerId) {
            foreach ($finishedPlayers as $finishedPlayer) {
                if ($finishedPlayer['playerId'] === $winnerId) {
                    $winnerRounds = $finishedPlayer['roundsPlayed'];
                    $winnerAverage = $finishedPlayer['roundAverage'];
                    $winnerName = $finishedPlayer['username'];

                    break;
                }
            }
        }

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
                placement: $finishedPlayer['placement'],
                roundsPlayed: $finishedPlayer['roundsPlayed'],
                roundAverage: $finishedPlayer['roundAverage'],
            );
        }

        return $result;
    }
}
