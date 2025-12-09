<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Game;
use App\Dto\GameSettingsRequest;
use App\Dto\StartGameRequest;
use App\Dto\ThrowRequest;
use App\Service\GameRoomServiceInterface;
use App\Service\GameSettingsServiceInterface;
use App\Service\GameFinishService;
use App\Service\GameServiceInterface;
use App\Service\GameStartServiceInterface;
use App\Service\GameStatisticsService;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\GameThrowServiceInterface;
use DateTimeInterface;
use InvalidArgumentException;
use App\Repository\GameRepositoryInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity as AttributeMapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Throwable;

/**
 * Controller to manage game actions such as starting a game and recording throws.
 */
final class GameController extends AbstractController
{
    #[Route('/api/game/{gameId}/start', name: 'app_game_start', methods: ['POST'], format: 'json')]
    /**
     * Starts a game with provided settings.
     *
     * @param Game                      $game
     * @param GameStartServiceInterface $gameStartService
     * @param StartGameRequest          $dto
     *
     * @return Response
     */
    public function start(
        #[AttributeMapEntity(id: 'gameId')] Game $game,
        GameStartServiceInterface $gameStartService,
        #[MapRequestPayload] StartGameRequest $dto,
    ): Response {
        // $game wird automatisch durch den Mapping geladen
        try {
            $gameStartService->start($game, $dto);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($game, context: ['groups' => 'game:read']);
    }

    /**
     * This function records a throw for a player in a game.
     */
    #[Route('/api/game/{gameId}/throw', name: 'app_game_throw', methods: ['POST'], format: 'json')]
    /**
     * @param Game                      $game
     * @param GameThrowServiceInterface $gameThrowService
     * @param GameServiceInterface      $gameService
     * @param ThrowRequest              $dto
     *
     * @return Response
     */
    public function throw(
        #[AttributeMapEntity(id: 'gameId')] Game $game,
        GameThrowServiceInterface $gameThrowService,
        GameServiceInterface $gameService,
        #[MapRequestPayload] ThrowRequest $dto,
    ): Response {
        // $dto wird automatisch durch den Mapping geladen
        // $game wird automatisch durch den Mapping geladen
        try {
            $gameThrowService->recordThrow($game, $dto);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $gameDto = $gameService->createGameDto($game);

        return $this->json($gameDto);
    }

    #[Route('/api/game/settings', name: 'app_game_settings_create', methods: ['POST'], format: 'json')]
    /**
     * @param GameRoomServiceInterface     $gameRoomService
     * @param GameSettingsServiceInterface $gameSettingsService
     * @param GameServiceInterface         $gameService
     * @param GameSettingsRequest          $dto
     *
     * @return Response
     */
    public function createSettings(
        GameRoomServiceInterface $gameRoomService,
        GameSettingsServiceInterface $gameSettingsService,
        GameServiceInterface $gameService,
        #[MapRequestPayload] GameSettingsRequest $dto,
    ): Response {
        // $dto wird automatisch durch den Mapping geladen
        $game = $gameRoomService->createGame();

        try {
            $gameSettingsService->updateSettings($game, $dto);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $gameDto = $gameService->createGameDto($game);

        return $this->json($gameDto, Response::HTTP_CREATED);
    }

    #[Route('/api/game/{gameId}/settings', name: 'app_game_settings', methods: ['PATCH'], format: 'json')]
    /**
     * @param Game                         $game
     * @param GameSettingsServiceInterface $gameSettingsService
     * @param GameServiceInterface         $gameService
     * @param GameSettingsRequest          $dto
     *
     * @return Response
     */
    public function updateSettings(
        #[AttributeMapEntity(id: 'gameId')] Game $game,
        GameSettingsServiceInterface $gameSettingsService,
        GameServiceInterface $gameService,
        #[MapRequestPayload] GameSettingsRequest $dto,
    ): Response {
        // $dto wird automatisch durch den Mapping geladen
        try {
            $gameSettingsService->updateSettings($game, $dto);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $gameDto = $gameService->createGameDto($game);

        return $this->json($gameDto);
    }

    #[Route('/api/game/{gameId}/throw', name: 'app_game_throw_undo', methods: ['DELETE'], format: 'json')]
    /**
     * @param GameThrowServiceInterface $gameThrowService
     * @param GameServiceInterface      $gameService
     *
     * @return Response
     */
    public function undoThrow(
        #[AttributeMapEntity(id: 'gameId')] Game $game,
        GameThrowServiceInterface $gameThrowService,
        GameServiceInterface $gameService
    ): Response {
        $gameThrowService->undoLastThrow($game);
        $gameDto = $gameService->createGameDto($game);

        return $this->json($gameDto);
    }

    #[Route('/api/game/{gameId}/finished', name: 'app_game_finished', methods: ['GET'], format: 'json')]
    /**
     * @param Game              $game
     * @param GameFinishService $gameFinishService
     *
     * @return Response
     */
    public function finished(
        #[AttributeMapEntity(id: 'gameId')] Game $game,
        GameFinishService $gameFinishService,
    ): Response {
        try {
            $result = $gameFinishService->finishGame($game);
        } catch (Throwable $e) {
            return $this->json(
                ['error' => 'An error occurred: '.$e->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $this->json($result, context: ['groups' => 'game:read']);
    }

    #[Route('/api/games/overview', name: 'app_games_overview', methods: ['GET'], format: 'json')]
    /**
     * @param GameRepositoryInterface $gameRepository
     * @param GameFinishService       $gameFinishService
     * @param int                     $limit
     * @param int                     $offset
     *
     * @return Response
     */
    public function gamesOverview(
        GameRepositoryInterface $gameRepository,
        GameFinishService $gameFinishService,
        #[MapQueryParameter] int $limit = 100,
        #[MapQueryParameter] int $offset = 0,
    ): Response {
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


    #[Route('/api/players/stats', name: 'app_players_stats', methods: ['GET'], format: 'json')]
    /**
     * @param GameStatisticsService          $gameStatisticsService
     * @param RoundThrowsRepositoryInterface $roundThrowsRepository
     * @param int                            $limit
     * @param int                            $offset
     * @param string                         $sort
     *
     * @return Response
     */
    public function playerStats(
        GameStatisticsService $gameStatisticsService,
        RoundThrowsRepositoryInterface $roundThrowsRepository,
        #[MapQueryParameter] int $limit = 20,
        #[MapQueryParameter] int $offset = 0,
        #[MapQueryParameter] string $sort = 'average:desc',
    ): Response {

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

    #[Route('/api/game/{gameId}', name: 'app_game_state', methods: ['GET'], format: 'json')]
    /**
     * @param Game                 $game
     * @param GameServiceInterface $gameService
     *
     * @return JsonResponse
     */
    public function getGameState(
        #[AttributeMapEntity(id: 'gameId')] Game $game,
        GameServiceInterface $gameService
    ): JsonResponse {
        // hier berechnen wir, sammeln die Wurfdaten, bauen Spielerliste mit allen Informationen
        $gameDto = $gameService->createGameDto($game);

        return $this->json($gameDto);
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
