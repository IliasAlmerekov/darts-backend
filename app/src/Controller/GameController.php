<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\StartGameRequest;
use App\Dto\ThrowRequest;
use App\Service\GameFinishService;
use App\Service\GameStartService;
use App\Service\GameStatisticsService;
use App\Service\GameThrowService;
use InvalidArgumentException;
use App\Repository\GameRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @throws InvalidArgumentException
 * Controller to manage game actions such as starting a game and recording throws.
 */
final class GameController extends AbstractController
{
    #[Route('/api/game/{gameId}/start', name: 'app_game_start', methods: ['POST'])]
    public function start(
        int $gameId,
        #[MapRequestPayload] StartGameRequest $dto,
        GameRepository $gameRepository,
        GameStartService $gameStartService,
    ): Response {
        $game = $gameRepository->find($gameId);

        if (!$game) {
            return $this->json(
                ['error' => 'Game not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        try {
            $gameStartService->start($game, $dto);
        } catch (InvalidArgumentException $e) {
            return $this->json(
                ['error' => $e->getMessage()],
                Response::HTTP_BAD_REQUEST
            );
        }

        return $this->json($game, context: ['groups' => 'game:read']);
    }

    /**
     * @throws InvalidArgumentException
     * This function records a throw for a player in a game.
     */
    #[Route('/api/game/{gameId}/throw', name: 'app_game_throw', methods: ['POST'])]
    public function throw(
        int $gameId,
        #[MapRequestPayload] ThrowRequest $dto,
        GameRepository $gameRepository,
        GameThrowService $gameThrowService,
    ): Response {
        $game = $gameRepository->find($gameId);

        if (!$game) {
            return $this->json(
                ['error' => 'Game not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        try {
            $gameThrowService->recordThrow($game, $dto);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($game, context: ['groups' => 'game:read']);
    }

    #[Route('/api/game/{gameId}/throw', name: 'app_game_throw_undo', methods: ['DELETE'])]
    public function undoThrow(
        int $gameId,
        GameRepository $gameRepository,
        GameThrowService $gameThrowService,
    ): Response {
        $game = $gameRepository->find($gameId);

        if (!$game) {
            return $this->json(
                ['error' => 'Game not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        $gameThrowService->undoLastThrow($game);

        return $this->json($game, context: ['groups' => 'game:read']);
    }

    #[Route('/api/game/{gameId}/finished', name: 'app_game_finished', methods: ['GET'])]
    public function finished(
        int $gameId,
        GameRepository $gameRepository,
        GameFinishService $gameFinishService,
    ): Response {
        $game = $gameRepository->find($gameId);

        if (!$game) {
            return $this->json(
                ['error' => 'Game not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        try {
            $result = $gameFinishService->finishGame(
                $game,
                null
            );
        } catch (\Throwable $e) {
            return $this->json(
                ['error' => 'An error occurred: ' . $e->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $this->json($result, context: ['groups' => 'game:read']);
    }

  
    #[Route('/api/players/stats', name: 'app_players_stats', methods: ['GET'])]
    public function playerStats(
        Request $request,
        GameStatisticsService $gameStatisticsService,
    ): Response {
        $limit = max(1, min(100, $request->query->getInt('limit', 20)));
        $offset = max(0, $request->query->getInt('offset', 0));

        $sortParam = (string) $request->query->get('sort', 'average:desc');
        [$sortField, $sortDirection] = $this->parseSort($sortParam);

        $stats = $gameStatisticsService->getPlayerStats($limit, $offset, $sortField, $sortDirection);

        return $this->json($stats, context: ['groups' => 'stats:read']);
    }

    private function parseSort(string $sort): array
    {
        $field = 'average';
        $direction = 'desc';

        if (str_contains($sort, ':')) {
            [$candidateField, $candidateDirection] = explode(':', $sort, 2);
            $field = strtolower(trim($candidateField)) ?: $field;
            $direction = strtolower(trim($candidateDirection)) ?: $direction;
        } elseif ($sort !== '') {
            $field = strtolower(trim($sort));
        }

        if (!in_array($field, ['average', 'gamesplayed'], true)) {
            $field = 'average';
        }

        $direction = $direction === 'asc' ? 'ASC' : 'DESC';

        if ($field === 'gamesplayed') {
            $field = 'gamesPlayed';
        }

        return [$field, $direction];
    }
}
