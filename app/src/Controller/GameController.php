<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Game;
use App\Dto\GameSettingsRequest;
use App\Dto\StartGameRequest;
use App\Dto\ThrowRequest;
use App\Enum\GameStatus;
use App\Service\GameRoomServiceInterface;
use App\Service\GameSettingsService;
use App\Service\GameFinishService;
use App\Service\GameServiceInterface;
use App\Service\GameStartServiceInterface;
use App\Service\GameStatisticsService;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\GameThrowServiceInterface;
use DateTimeInterface;
use InvalidArgumentException;
use App\Repository\GameRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;

/**
 * @throws InvalidArgumentException
 * Controller to manage game actions such as starting a game and recording throws.
 */
final class GameController extends AbstractController
{
    #[Route('/api/game/{gameId}/start', name: 'app_game_start', methods: ['POST'])]
    /**
     * @param int                     $gameId
     * @param Request                 $request
     * @param GameRepositoryInterface $gameRepository
     * @param GameStartServiceInterface $gameStartService
     * @param SerializerInterface     $serializer
     *
     * @return Response
     *
     * @throws ExceptionInterface
     */
    public function start(
        int $gameId,
        Request $request,
        GameRepositoryInterface $gameRepository,
        GameStartServiceInterface $gameStartService,
        SerializerInterface $serializer,
    ): Response {
        $dto = $serializer->deserialize($request->getContent(), StartGameRequest::class, 'json');
        $game = $gameRepository->find($gameId);
        if (!$game instanceof Game) {
            return $this->json(['error' => 'Game not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $gameStartService->start($game, $dto);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($game, context: ['groups' => 'game:read']);
    }

    /**
     * @throws InvalidArgumentException|ExceptionInterface
     * This function records a throw for a player in a game.
     */
    #[Route('/api/game/{gameId}/throw', name: 'app_game_throw', methods: ['POST'])]
    /**
     * @param int                     $gameId
     * @param Request                 $request
     * @param GameRepositoryInterface $gameRepository
     * @param GameThrowService        $gameThrowService
     * @param GameServiceInterface    $gameService
     * @param SerializerInterface     $serializer
     *
     * @return Response
     */
    public function throw(
        int $gameId,
        Request $request,
        GameRepositoryInterface $gameRepository,
        GameThrowServiceInterface $gameThrowService,
        GameServiceInterface $gameService,
        SerializerInterface $serializer,
    ): Response {
        $dto = $serializer->deserialize($request->getContent(), ThrowRequest::class, 'json');
        $game = $gameRepository->find($gameId);
        if (!$game instanceof Game) {
            return $this->json(['error' => 'Game not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $gameThrowService->recordThrow($game, $dto);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $gameDto = $gameService->createGameDto($game);

        return $this->json($gameDto);
    }

    #[Route('/api/game/settings', name: 'app_game_settings_create', methods: ['POST'])]
    /**
     * @param Request                   $request
     * @param SerializerInterface       $serializer
     * @param GameRoomServiceInterface  $gameRoomService
     * @param GameSettingsService       $gameSettingsService
     * @param GameServiceInterface      $gameService
     *
     * @return Response
     */
    public function createSettings(
        Request $request,
        SerializerInterface $serializer,
        GameRoomServiceInterface $gameRoomService,
        GameSettingsService $gameSettingsService,
        GameServiceInterface $gameService,
    ): Response {
        $dto = $serializer->deserialize($request->getContent(), GameSettingsRequest::class, 'json');
        $game = $gameRoomService->createGame();

        try {
            $gameSettingsService->updateSettings($game, $dto);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $gameDto = $gameService->createGameDto($game);

        return $this->json($gameDto, Response::HTTP_CREATED);
    }

    #[Route('/api/game/{gameId}/settings', name: 'app_game_settings', methods: ['PATCH'])]
    /**
     * @param int                     $gameId
     * @param Request                 $request
     * @param GameRepositoryInterface $gameRepository
     * @param GameSettingsService     $gameSettingsService
     * @param GameServiceInterface    $gameService
     * @param SerializerInterface     $serializer
     *
     * @return Response
     */
    public function updateSettings(
        int $gameId,
        Request $request,
        GameRepositoryInterface $gameRepository,
        GameSettingsService $gameSettingsService,
        GameServiceInterface $gameService,
        SerializerInterface $serializer,
    ): Response {
        $dto = $serializer->deserialize($request->getContent(), GameSettingsRequest::class, 'json');
        $game = $gameRepository->find($gameId);
        if (!$game instanceof Game) {
            return $this->json(['error' => 'Game not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $gameSettingsService->updateSettings($game, $dto);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $gameDto = $gameService->createGameDto($game);

        return $this->json($gameDto);
    }

    #[Route('/api/game/{gameId}/throw', name: 'app_game_throw_undo', methods: ['DELETE'])]
    /**
     * @param int                       $gameId
     * @param GameRepositoryInterface   $gameRepository
     * @param GameThrowServiceInterface $gameThrowService
     * @param GameServiceInterface      $gameService
     *
     * @return Response
     */
    public function undoThrow(
        int $gameId,
        GameRepositoryInterface $gameRepository,
        GameThrowServiceInterface $gameThrowService,
        GameServiceInterface $gameService
    ): Response {
        $game = $gameRepository->find($gameId);
        if (!$game instanceof Game) {
            return $this->json(['error' => 'Game not found'], Response::HTTP_NOT_FOUND);
        }

        $gameThrowService->undoLastThrow($game);
        $gameDto = $gameService->createGameDto($game);

        return $this->json($gameDto);
    }

    #[Route('/api/game/{gameId}/finished', name: 'app_game_finished', methods: ['GET'])]
    /**
     * @param int                     $gameId
     * @param GameRepositoryInterface $gameRepository
     * @param GameFinishService       $gameFinishService
     *
     * @return Response
     */
    public function finished(
        int $gameId,
        GameRepositoryInterface $gameRepository,
        GameFinishService $gameFinishService,
    ): Response {
        $game = $gameRepository->find($gameId);
        if (!$game instanceof Game) {
            return $this->json(['error' => 'Game not found'], Response::HTTP_NOT_FOUND);
        }

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

    #[Route('/api/games/overview', name: 'app_games_overview', methods: ['GET'])]
    /**
     * @param Request                 $request
     * @param GameRepositoryInterface $gameRepository
     * @param GameFinishService       $gameFinishService
     *
     * @return Response
     */
    public function gamesOverview(
        Request $request,
        GameRepositoryInterface $gameRepository,
        GameFinishService $gameFinishService,
    ): Response {
        $limit = max(1, min(100, $request->query->getInt('limit', 100)));
        $offset = max(0, $request->query->getInt('offset'));
        $games = $gameRepository->createQueryBuilder('g')
            ->andWhere('g.status = :status')
            ->setParameter('status', GameStatus::Finished)
            ->orderBy('g.gameId', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
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


    #[Route('/api/players/stats', name: 'app_players_stats', methods: ['GET'])]
    /**
     * @param Request                        $request
     * @param GameStatisticsService          $gameStatisticsService
     * @param RoundThrowsRepositoryInterface $roundThrowsRepository
     *
     * @return Response
     */
    public function playerStats(
        Request $request,
        GameStatisticsService $gameStatisticsService,
        RoundThrowsRepositoryInterface $roundThrowsRepository,
    ): Response {
        $limit = max(1, min(100, $request->query->getInt('limit', 20)));
        $offset = max(0, $request->query->getInt('offset'));
        $sortParam = (string) $request->query->get('sort', 'average:desc');
        [$sortField, $sortDirection] = $this->parseSort($sortParam);
        $stats = $gameStatisticsService->getPlayerStats($limit, $offset, $sortField, $sortDirection);

        return $this->json([
            'limit' => $limit,
            'offset' => $offset,
            'total' => $roundThrowsRepository->countPlayersWithFinishedRounds(),
            'items' => $stats,
        ], context: ['groups' => 'stats:read']);
    }

    #[Route('/api/game/{gameId}', name: 'app_game_state', methods: ['GET'])]
    /**
     * @param int                     $gameId
     * @param GameRepositoryInterface $gameRepository
     * @param GameServiceInterface    $gameService
     *
     * @return JsonResponse
     */
    public function getGameState(
        int $gameId,
        GameRepositoryInterface $gameRepository,
        GameServiceInterface $gameService
    ): JsonResponse {
        // Spiel aus der Datenbank abrufen
        $game = $gameRepository->find($gameId);
// Überprüfen, ob das Spiel existiert
        if (!$game instanceof Game) {
            return $this->json(['error' => 'Game not found'], Response::HTTP_NOT_FOUND);
        }
        // GameService verwenden, um das GameResponseDto zu erstellen,
        // hier berechnen wir, sammeln die Wurfdaten, bauen Spielerliste mit allen Informationen
        $gameDto = $gameService->createGameDto($game);
// Das DTO als JSON-Antwort zurückgeben,
        // wir konvertieren das DTO automatisch in JSON,
        // alle public werden zu JSON-Feldern
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
