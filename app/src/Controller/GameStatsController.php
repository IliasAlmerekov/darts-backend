<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Repository\GameRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Game\GameFinishServiceInterface;
use App\Service\Game\GameStatisticsServiceInterface;
use DateTimeInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints for game and player statistics.
 */
final class GameStatsController extends AbstractController
{
    /**
     * Returns overview of finished games.
     *
     * @param GameRepositoryInterface    $gameRepository
     * @param GameFinishServiceInterface $gameFinishService
     * @param int                        $limit
     * @param int                        $offset
     *
     * @return Response
     */
    #[Route('/api/games/overview', name: 'app_games_overview', methods: ['GET'], format: 'json')]
    public function gamesOverview(GameRepositoryInterface $gameRepository, GameFinishServiceInterface $gameFinishService, #[MapQueryParameter] int $limit = 100, #[MapQueryParameter] int $offset = 0): Response
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $games = $gameRepository->findFinished($limit, $offset);

        $items = [];
        foreach ($games as $game) {
            $stats = $gameFinishService->getGameStats($game);
            $items[] = [
                'id' => $stats['gameId'],
                'date' => $stats['date']?->format(DateTimeInterface::ATOM),
                'finishedAt' => $stats['finishedAt']?->format(DateTimeInterface::ATOM),
                'playersCount' => count($stats['finishedPlayers']),
                'winnerName' => $stats['winner']['username'] ?? null,
                'winnerId' => $stats['winner']['id'] ?? null,
                'winnerRounds' => $stats['winnerRoundsPlayed'],
            ];
        }

        return $this->json([
            'limit' => $limit,
            'offset' => $offset,
            'items' => $items,
            'total' => $gameRepository->countFinishedGames(),
        ]);
    }

    /**
     * Returns aggregated player stats.
     *
     * @param GameStatisticsServiceInterface $gameStatisticsService
     * @param RoundThrowsRepositoryInterface $roundThrowsRepository
     * @param int                            $limit
     * @param int                            $offset
     * @param string                         $sort
     *
     * @return Response
     */
    #[Route('/api/players/stats', name: 'app_players_stats', methods: ['GET'], format: 'json')]
    public function playerStats(GameStatisticsServiceInterface $gameStatisticsService, RoundThrowsRepositoryInterface $roundThrowsRepository, #[MapQueryParameter] int $limit = 20, #[MapQueryParameter] int $offset = 0, #[MapQueryParameter] string $sort = 'average:desc'): Response
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        [$sortField, $sortDirection] = $this->parseSort($sort);
        $stats = $gameStatisticsService->getPlayerStats($limit, $offset, $sortField, $sortDirection);

        return $this->json([
            'limit' => $limit,
            'offset' => $offset,
            'total' => $roundThrowsRepository->countPlayersWithFinishedRounds(),
            'items' => $stats,
        ], context: ['groups' => 'stats:read']);
    }

    /**
     * @param string $sort
     *
     * @return array{0:string,1:string}
     */
    private function parseSort(string $sort): array
    {
        $field = 'average';
        $direction = 'desc';
        if (str_contains($sort, ':')) {
            $parts = explode(':', $sort, 2);
            $candidateField = $parts[0] ?? '';
            $candidateDirection = $parts[1] ?? '';
            $field = strtolower(trim($candidateField)) ?: $field;
            $direction = strtolower(trim($candidateDirection)) ?: $direction;
        } elseif ('' !== $sort) {
            $field = strtolower(trim($sort));
        }

        if (!in_array($field, ['average', 'gamesplayed'], true)) {
            $field = 'average';
        }

        $direction = 'asc' === $direction ? 'ASC' : 'DESC';
        if ('gamesplayed' === $field) {
            $field = 'gamesPlayed';
        }

        return [$field, $direction];
    }
}
