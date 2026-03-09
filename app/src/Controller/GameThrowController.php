<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\GameResponseDto;
use App\Dto\ThrowAckDto;
use App\Dto\ThrowRequest;
use App\Entity\Game;
use App\Http\Attribute\ApiResponse;
use App\Service\Game\GameDeltaServiceInterface;
use App\Service\Game\GameServiceInterface;
use App\Service\Game\GameThrowServiceInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bridge\Doctrine\Attribute\MapEntity as AttributeMapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints for recording and undoing throws.
 */
#[OA\Tag(name: 'Game Throws')]
final class GameThrowController extends AbstractController
{
    /**
     * Records a throw for the current player.
     *
     * @param Game                      $game
     * @param GameThrowServiceInterface $gameThrowService
     * @param GameServiceInterface      $gameService
     * @param ThrowRequest              $dto
     *
     * @return JsonResponse
     */
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ThrowRequest::class)))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Spielzustand nach dem Erfassen des Wurfs.',
        content: new OA\JsonContent(ref: new Model(type: GameResponseDto::class))
    )]
    #[OA\Response(
        response: Response::HTTP_CONFLICT,
        description: 'Wurf ist im aktuellen Zustand nicht erlaubt (z. B. falscher aktiver Spieler).'
    )]
    #[ApiResponse]
    #[Route('/api/game/{gameId}/throw', name: 'app_game_throw', methods: ['POST'], format: 'json')]
    public function throw(#[AttributeMapEntity(id: 'gameId')] Game $game, GameThrowServiceInterface $gameThrowService, GameServiceInterface $gameService, #[MapRequestPayload] ThrowRequest $dto): JsonResponse
    {
        $gameThrowService->recordThrow($game, $dto);

        $gameDto = $gameService->createGameDto($game);

        $response = $this->json($gameDto);
        $response->headers->set('Deprecation', 'true');
        $response->headers->set('Sunset', 'Wed, 30 Sep 2026 23:59:59 GMT');
        $response->headers->set('Link', sprintf('</api/game/%d/throw/delta>; rel="successor-version"', (int) $game->getGameId()));

        return $response;
    }

    /**
     * Records a throw and returns compact delta payload.
     *
     * @param Game                      $game
     * @param GameThrowServiceInterface $gameThrowService
     * @param GameDeltaServiceInterface $gameDeltaService
     * @param ThrowRequest              $dto
     *
     * @return ThrowAckDto
     */
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ThrowRequest::class)))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Kompakter Delta-Ack nach einem Wurf.',
        content: new OA\JsonContent(ref: new Model(type: ThrowAckDto::class))
    )]
    #[OA\Response(
        response: Response::HTTP_CONFLICT,
        description: 'Wurf ist im aktuellen Zustand nicht erlaubt.'
    )]
    #[ApiResponse]
    #[Route('/api/game/{gameId}/throw/delta', name: 'app_game_throw_delta', methods: ['POST'], format: 'json')]
    public function throwDelta(#[AttributeMapEntity(id: 'gameId')] Game $game, GameThrowServiceInterface $gameThrowService, GameDeltaServiceInterface $gameDeltaService, #[MapRequestPayload] ThrowRequest $dto): ThrowAckDto
    {
        $gameThrowService->recordThrow($game, $dto);

        return $gameDeltaService->buildThrowAck($game);
    }

    /**
     * Undoes the last recorded throw.
     *
     * @param Game                      $game
     * @param GameThrowServiceInterface $gameThrowService
     * @param GameServiceInterface      $gameService
     *
     * @return mixed
     */
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Spielzustand nach dem Rückgängigmachen des letzten Wurfs.',
        content: new OA\JsonContent(ref: new Model(type: GameResponseDto::class))
    )]
    #[ApiResponse]
    #[Route('/api/game/{gameId}/throw', name: 'app_game_throw_undo', methods: ['DELETE'], format: 'json')]
    public function undoThrow(#[AttributeMapEntity(id: 'gameId')] Game $game, GameThrowServiceInterface $gameThrowService, GameServiceInterface $gameService): mixed
    {
        $gameThrowService->undoLastThrow($game);
        $gameDto = $gameService->createGameDto($game);

        return $gameDto;
    }
}
