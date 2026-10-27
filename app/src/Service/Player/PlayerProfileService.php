<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Player;

use App\Dto\PlayerProfileGameDto;
use App\Dto\PlayerProfileParticipantDto;
use App\Dto\PlayerProfileResponseDto;
use App\Entity\User;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\GameRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Game\GameStatisticsServiceInterface;
use App\Service\Game\ParticipantResult;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Override;

/**
 * Builds a player's own Profile: their statistics and their most recent finished games.
 * The games, their seats, the throw totals and the throws behind the statistics load in a
 * fixed number of queries.
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
     * @param RoundThrowsRepositoryInterface $roundThrowsRepository
     * @param EntityManagerInterface         $entityManager
     */
    public function __construct(
        private GameStatisticsServiceInterface $gameStatisticsService,
        private GameRepositoryInterface $gameRepository,
        private GamePlayersRepositoryInterface $gamePlayersRepository,
        private RoundThrowsRepositoryInterface $roundThrowsRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param User $player
     *
     * @return PlayerProfileResponseDto
     */
    #[Override]
    public function getProfile(User $player): PlayerProfileResponseDto
    {
        $playerId = (int) $player->getId();

        // One transaction, so MySQL's repeatable read serves every query from one snapshot and a
        // game finishing between them cannot make wins exceed gamesPlayed.
        return $this->entityManager->wrapInTransaction(function () use ($player, $playerId): PlayerProfileResponseDto {
            $stats = $this->gameStatisticsService->getPlayerStatsForPlayer($playerId);

            return new PlayerProfileResponseDto(
                stats: PlayerThrowStatistics::build(
                    $stats?->gamesPlayed ?? 0,
                    $stats?->scoreAverage,
                    $this->roundThrowsRepository->findThrowsInCountedGamesForPlayer($playerId),
                ),
                recentGames: $this->buildRecentGames($playerId),
                registeredAt: $player->getRegisteredAt()?->format(DateTimeInterface::ATOM),
            );
        });
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
                    placement: $seat->getPlacement(),
                    isSelf: $isSelf,
                );
            }

            $recentGames[] = new PlayerProfileGameDto(
                gameId: $gameId,
                finishedAt: $game['finishedAt']?->format(DateTimeInterface::ATOM),
                placement: $ownSeat?->getPlacement(),
                roundAverage: ParticipantResult::threeDartAverage($totals[$playerId] ?? null),
                participantsCount: count($participants),
                participants: $participants,
            );
        }

        return $recentGames;
    }
}
