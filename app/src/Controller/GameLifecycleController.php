<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\GameSettingsRequest;
use App\Dto\SuccessMessageDto;
use App\Dto\StartGameRequest;
use App\Entity\Game;
use App\Http\Attribute\ApiResponse;
use App\Service\Game\GameAbortServiceInterface;
use App\Service\Game\GameFinishServiceInterface;
use App\Service\Game\GameRoomServiceInterface;
use App\Service\Game\GameServiceInterface;
use App\Service\Game\GameSettingsServiceInterface;
use App\Service\Game\GameStartServiceInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity as AttributeMapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lifecycle endpoints for games: start, settings, finish, state.
 */
final class GameLifecycleController extends AbstractController
{
    /**
     * Starts a game with provided settings.
     *
     * @param Game                      $game
     * @param GameStartServiceInterface $gameStartService
     * @param StartGameRequest          $dto
     *
     * @return Game
     */
    #[ApiResponse(groups: ['game:read'])]
    #[Route('/api/game/{gameId}/start', name: 'app_game_start', methods: ['POST'], format: 'json')]
    public function start(#[AttributeMapEntity(id: 'gameId')] Game $game, GameStartServiceInterface $gameStartService, #[MapRequestPayload] StartGameRequest $dto): Game
    {
        $gameStartService->start($game, $dto);

        return $game;
    }

    /**
     * @param GameRoomServiceInterface     $gameRoomService
     * @param GameSettingsServiceInterface $gameSettingsService
     * @param GameServiceInterface         $gameService
     * @param GameSettingsRequest          $dto
     *
     * @return mixed
     */
    #[ApiResponse(status: Response::HTTP_CREATED)]
    #[Route('/api/game/settings', name: 'app_game_settings_create', methods: ['POST'], format: 'json')]
    public function createSettings(GameRoomServiceInterface $gameRoomService, GameSettingsServiceInterface $gameSettingsService, GameServiceInterface $gameService, #[MapRequestPayload] GameSettingsRequest $dto): mixed
    {
        $game = $gameRoomService->createGame();

        $gameSettingsService->updateSettings($game, $dto);

        $gameDto = $gameService->createGameDto($game);

        return $gameDto;
    }

    /**
     * @param Game                         $game
     * @param GameSettingsServiceInterface $gameSettingsService
     * @param GameServiceInterface         $gameService
     * @param GameSettingsRequest          $dto
     *
     * @return mixed
     */
    #[ApiResponse]
    #[Route('/api/game/{gameId}/settings', name: 'app_game_settings', methods: ['PATCH'], format: 'json')]
    public function updateSettings(#[AttributeMapEntity(id: 'gameId')] Game $game, GameSettingsServiceInterface $gameSettingsService, GameServiceInterface $gameService, #[MapRequestPayload] GameSettingsRequest $dto): mixed
    {
        $gameSettingsService->updateSettings($game, $dto);

        $gameDto = $gameService->createGameDto($game);

        return $gameDto;
    }

    /**
     * @param Game                       $game
     * @param GameFinishServiceInterface $gameFinishService
     *
     * @return mixed
     */
    #[ApiResponse(groups: ['game:read'])]
    #[Route('/api/game/{gameId}/finished', name: 'app_game_finished', methods: ['GET'], format: 'json')]
    public function finished(#[AttributeMapEntity(id: 'gameId')] Game $game, GameFinishServiceInterface $gameFinishService): mixed
    {
        $result = $gameFinishService->finishGame($game);

        return $result;
    }

    /**
     * @param Game                 $game
     * @param GameServiceInterface $gameService
     *
     * @return mixed
     */
    #[ApiResponse]
    #[Route('/api/game/{gameId}', name: 'app_game_state', methods: ['GET'], format: 'json')]
    public function getGameState(#[AttributeMapEntity(id: 'gameId')] Game $game, GameServiceInterface $gameService): mixed
    {
        $gameDto = $gameService->createGameDto($game);

        return $gameDto;
    }

    /**
     * @param Game                      $game
     * @param GameAbortServiceInterface $gameAbortService
     *
     * @return SuccessMessageDto
     */
    #[ApiResponse]
    #[Route('/api/game/{gameId}/abort', name: 'app_game_abort', methods: ['PATCH'], format: 'json')]
    public function abortGame(#[AttributeMapEntity(id: 'gameId')] Game $game, GameAbortServiceInterface $gameAbortService): SuccessMessageDto
    {
        $gameAbortService->abortGame($game);

        return new SuccessMessageDto('Game aborted successfully');
    }
}
