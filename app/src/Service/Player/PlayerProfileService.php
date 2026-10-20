<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Player;

use App\Dto\ParticipantTotalsDto;
use App\Dto\PlayerProfileGameDto;
use App\Dto\PlayerProfileParticipantDto;
use App\Dto\PlayerProfileResponseDto;
use App\Dto\PlayerProfileStatsDto;
use App\Entity\GamePlayers;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\GameRepositoryInterface;
use App\Repository\RoundRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Game\GameStatisticsServiceInterface;
use App\Service\Game\ParticipantResult;
use DateTimeInterface;
use Override;

/**
 * Builds a player's own Profile: their statistics and their most recent finished games.
 * The games, their seats and the throw totals load in a fixed number of queries.
 *
 * @psalm-suppress UnusedClass Reason: service is auto-wired and used via interface.
 */
final readonly class PlayerProfileService implements PlayerProfileServiceInterface
{
    private const int RECENT_GAMES_LIMIT = 10;

    /**
     * @param GameStatisticsServiceInterface $gameStatisticsService
     * @param GameRepositoryInterface        $gameRepository
     * @param GamePlayersRepositoryInterface $gamePlayersRepository
     * @param RoundRepositoryInterface       $roundRepository
     * @param RoundThrowsRepositoryInterface $roundThrowsRepository
     */
    public function __construct(
        private GameStatisticsServiceInterface $gameStatisticsService,
        private GameRepositoryInterface $gameRepository,
        private GamePlayersRepositoryInterface $gamePlayersRepository,
        private RoundRepositoryInterface $roundRepository,
        private RoundThrowsRepositoryInterface $roundThrowsRepository,
    ) {
    }

    /**
     * @param int $playerId
     *
     * @return PlayerProfileResponseDto
     */
    #[Override]
    public function getProfile(int $playerId): PlayerProfileResponseDto
    {
        $stats = $this->gameStatisticsService->getPlayerStatsForPlayer($playerId);

        return new PlayerProfileResponseDto(
            stats: new PlayerProfileStatsDto(
                gamesPlayed: $stats?->gamesPlayed ?? 0,
                scoreAverage: $stats?->scoreAverage,
            ),
            recentGames: $this->buildRecentGames($playerId),
        );
    }

    /**
     * @param int $playerId
     *
     * @return list<PlayerProfileGameDto>
     */
    private function buildRecentGames(int $playerId): array
    {
        $games = $this->gameRepository->findRecentFinishedForPlayer($playerId, self::RECENT_GAMES_LIMIT);
        if ([] === $games) {
            return [];
        }

        $gameIds = array_map(static fn(array $game): int => $game['gameId'], $games);
        $seatsByGameId = $this->gamePlayersRepository->findByGameIds($gameIds);
        $finishedRoundsByGameId = $this->roundRepository->countFinishedRoundsByGameIds($gameIds);
        $totalsByGameId = $this->roundThrowsRepository->getParticipantTotalsForGames($gameIds);

        $recentGames = [];
        foreach ($games as $game) {
            $gameId = $game['gameId'];
            $seats = $seatsByGameId[$gameId] ?? [];
            usort($seats, ParticipantResult::compareByPlacement(...));
            $totals = $totalsByGameId[$gameId] ?? [];

            $ownSeat = null;
            $participants = [];
            foreach ($seats as $seat) {
                $user = $seat->getPlayer();
                $isSelf = null !== $user && $user->getId() === $playerId;
                if ($isSelf) {
                    $ownSeat = $seat;
                }
                $participants[] = new PlayerProfileParticipantDto(
                    playerId: null !== $user && !$user->isGuest() ? $user->getId() : null,
                    displayName: ParticipantResult::displayName($seat),
                    placement: $seat->getPosition(),
                    isSelf: $isSelf,
                );
            }

            $recentGames[] = new PlayerProfileGameDto(
                gameId: $gameId,
                finishedAt: $game['finishedAt']?->format(DateTimeInterface::ATOM),
                placement: $ownSeat?->getPosition(),
                roundAverage: null !== $ownSeat
                    ? $this->ownRoundAverage($ownSeat, $playerId, $finishedRoundsByGameId[$gameId] ?? 0, $totals)
                    : 0.0,
                participantsCount: count($participants),
                participants: $participants,
            );
        }

        return $recentGames;
    }

    /**
     * The player's round average in one game, computed like the game summary does.
     *
     * @param GamePlayers                      $ownSeat
     * @param int                              $playerId
     * @param int                              $finishedRounds
     * @param array<int, ParticipantTotalsDto> $totals         per player id
     *
     * @return float
     */
    private function ownRoundAverage(GamePlayers $ownSeat, int $playerId, int $finishedRounds, array $totals): float
    {
        $maxRoundNumber = ParticipantResult::maxRoundNumber(
            $finishedRounds,
            array_map(static fn(ParticipantTotalsDto $total): int => $total->lastRound, $totals)
        );
        $ownTotals = $totals[$playerId] ?? null;
        $roundsPlayed = ParticipantResult::roundsPlayed(
            $ownSeat->getScore(),
            $ownTotals?->roundsPlayed,
            $maxRoundNumber
        );

        return ParticipantResult::roundAverage($ownTotals->totalScore ?? 0.0, $roundsPlayed);
    }
}
