<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\GameSettingsRequest;
use App\Dto\StartGameRequest;
use App\Entity\Game;
use App\Service\Game\GameFinishServiceInterface;
use App\Service\Game\GameRoomServiceInterface;
use App\Service\Game\GameServiceInterface;
use App\Service\Game\GameSettingsServiceInterface;
use App\Service\Game\GameStartServiceInterface;
use InvalidArgumentException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity as AttributeMapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Lifecycle endpoints for games: start, settings, finish, state.
 */
final class GameLifecycleController extends AbstractController
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
        try {
            $gameStartService->start($game, $dto);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($game, context: ['groups' => 'game:read']);
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
        try {
            $gameSettingsService->updateSettings($game, $dto);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $gameDto = $gameService->createGameDto($game);

        return $this->json($gameDto);
    }

    #[Route('/api/game/{gameId}/finished', name: 'app_game_finished', methods: ['GET'], format: 'json')]
    /**
     * @param Game                       $game
     * @param GameFinishServiceInterface $gameFinishService
     *
     * @return Response
     */
    public function finished(
        #[AttributeMapEntity(id: 'gameId')] Game $game,
        GameFinishServiceInterface $gameFinishService,
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
        $gameDto = $gameService->createGameDto($game);

        return $this->json($gameDto);
    }
}
