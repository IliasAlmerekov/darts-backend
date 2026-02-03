<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\GameSettingsRequest;
use App\Dto\GameResponseDto;
use App\Dto\SuccessMessageDto;
use App\Dto\StartGameRequest;
use App\Entity\Game;
use App\Exception\ApiExceptionInterface;
use App\Http\Attribute\ApiResponse;
use App\Service\Game\GameAbortServiceInterface;
use App\Service\Game\GameFinishServiceInterface;
use App\Service\Game\GameRoomServiceInterface;
use App\Service\Game\GameServiceInterface;
use App\Service\Game\GameSettingsServiceInterface;
use App\Service\Game\GameStartServiceInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bridge\Doctrine\Attribute\MapEntity as AttributeMapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lifecycle endpoints for games: start, settings, finish, state.
 */
#[OA\Tag(name: 'Game Lifecycle')]
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
     *
     * @throws ApiExceptionInterface
     */
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: StartGameRequest::class)))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Aktualisierter Spielzustand nach dem Start.',
        content: new OA\JsonContent(ref: new Model(type: Game::class, groups: ['game:read']))
    )]
    #[ApiResponse(groups: ['game:read'])]
    #[Route('/api/game/{gameId}/start', name: 'app_game_start', methods: ['POST'], format: 'json')]
    public function start(#[AttributeMapEntity(id: 'gameId')] Game $game, GameStartServiceInterface $gameStartService, #[MapRequestPayload] StartGameRequest $dto): Game
    {
        $gameStartService->start($game, $dto);

        return $game;
    }

    /**
     * Creates a game and applies settings.
     *
     * @param GameRoomServiceInterface     $gameRoomService
     * @param GameSettingsServiceInterface $gameSettingsService
     * @param GameServiceInterface         $gameService
     * @param GameSettingsRequest          $dto
     *
     * @return mixed
     */
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: GameSettingsRequest::class)))]
    #[OA\Response(
        response: Response::HTTP_CREATED,
        description: 'Spiel wurde mit Einstellungen erstellt.',
        content: new OA\JsonContent(ref: new Model(type: GameResponseDto::class))
    )]
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
     * Updates settings for an existing game.
     *
     * @param Game                         $game
     * @param GameSettingsServiceInterface $gameSettingsService
     * @param GameServiceInterface         $gameService
     * @param GameSettingsRequest          $dto
     *
     * @return mixed
     */
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: GameSettingsRequest::class)))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Spieleinstellungen wurden aktualisiert.',
        content: new OA\JsonContent(ref: new Model(type: GameResponseDto::class))
    )]
    #[ApiResponse]
    #[Route('/api/game/{gameId}/settings', name: 'app_game_settings', methods: ['PATCH'], format: 'json')]
    public function updateSettings(#[AttributeMapEntity(id: 'gameId')] Game $game, GameSettingsServiceInterface $gameSettingsService, GameServiceInterface $gameService, #[MapRequestPayload] GameSettingsRequest $dto): mixed
    {
        $gameSettingsService->updateSettings($game, $dto);

        $gameDto = $gameService->createGameDto($game);

        return $gameDto;
    }

    /**
     * Finishes a game and returns final standings.
     *
     * @param Game                       $game
     * @param GameFinishServiceInterface $gameFinishService
     *
     * @return mixed
     */
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Spiel beenden und Endplatzierungen zurückgeben.',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(
                type: 'object',
                properties: [
                    new OA\Property(property: 'playerId', type: 'integer', nullable: true, example: 1),
                    new OA\Property(property: 'username', type: 'string', nullable: true, example: 'alice'),
                    new OA\Property(property: 'position', type: 'integer', nullable: true, example: 1),
                    new OA\Property(property: 'roundsPlayed', type: 'integer', nullable: true, example: 10),
                    new OA\Property(property: 'roundAverage', type: 'number', format: 'float', example: 54.2),
                ]
            )
        )
    )]
    #[ApiResponse]
    #[Route('/api/game/{gameId}/finish', name: 'app_game_finish', methods: ['POST'], format: 'json')]
    public function finish(#[AttributeMapEntity(id: 'gameId')] Game $game, GameFinishServiceInterface $gameFinishService): mixed
    {
        $result = $gameFinishService->finishGame($game);

        return $result;
    }

    /**
     * Returns final standings without mutating game state.
     *
     * @param Game                       $game
     * @param GameFinishServiceInterface $gameFinishService
     *
     * @return mixed
     */
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Endplatzierungen des Spiels (read-only).',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(
                type: 'object',
                properties: [
                    new OA\Property(property: 'playerId', type: 'integer', nullable: true, example: 1),
                    new OA\Property(property: 'username', type: 'string', nullable: true, example: 'alice'),
                    new OA\Property(property: 'position', type: 'integer', nullable: true, example: 1),
                    new OA\Property(property: 'roundsPlayed', type: 'integer', nullable: true, example: 10),
                    new OA\Property(property: 'roundAverage', type: 'number', format: 'float', example: 54.2),
                ]
            )
        )
    )]
    #[ApiResponse]
    #[Route('/api/game/{gameId}/finished', name: 'app_game_finished', methods: ['GET'], format: 'json')]
    public function finished(#[AttributeMapEntity(id: 'gameId')] Game $game, GameFinishServiceInterface $gameFinishService): mixed
    {
        return $gameFinishService->getFinishedPlayers($game);
    }

    /**
     * Returns the current game state.
     *
     * @param Game                 $game
     * @param GameServiceInterface $gameService
     *
     * @return mixed
     */
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Aktueller Spielzustand.',
        content: new OA\JsonContent(ref: new Model(type: GameResponseDto::class))
    )]
    #[ApiResponse]
    #[Route('/api/game/{gameId}', name: 'app_game_state', methods: ['GET'], format: 'json')]
    public function getGameState(#[AttributeMapEntity(id: 'gameId')] Game $game, GameServiceInterface $gameService): mixed
    {
        $gameDto = $gameService->createGameDto($game);

        return $gameDto;
    }

    /**
     * Aborts a game.
     *
     * @param Game                      $game
     * @param GameAbortServiceInterface $gameAbortService
     *
     * @return SuccessMessageDto
     */
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Spiel wurde abgebrochen.',
        content: new OA\JsonContent(ref: new Model(type: SuccessMessageDto::class))
    )]
    #[ApiResponse]
    #[Route('/api/game/{gameId}/abort', name: 'app_game_abort', methods: ['PATCH'], format: 'json')]
    public function abortGame(#[AttributeMapEntity(id: 'gameId')] Game $game, GameAbortServiceInterface $gameAbortService): SuccessMessageDto
    {
        $gameAbortService->abortGame($game);

        return new SuccessMessageDto('Game aborted successfully');
    }
}
