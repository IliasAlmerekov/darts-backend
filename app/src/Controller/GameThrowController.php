<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\GameResponseDto;
use App\Dto\ThrowRequest;
use App\Entity\Game;
use App\Http\Attribute\ApiResponse;
use App\Service\Game\GameServiceInterface;
use App\Service\Game\GameThrowServiceInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bridge\Doctrine\Attribute\MapEntity as AttributeMapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints for recording and undoing throws.
 */
final class GameThrowController extends AbstractController
{
    /**
     * @param Game                      $game
     * @param GameThrowServiceInterface $gameThrowService
     * @param GameServiceInterface      $gameService
     * @param ThrowRequest              $dto
     *
     * @return mixed
     */
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ThrowRequest::class)))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Spielzustand nach dem Erfassen des Wurfs.',
        content: new OA\JsonContent(ref: new Model(type: GameResponseDto::class))
    )]
    #[ApiResponse]
    #[Route('/api/game/{gameId}/throw', name: 'app_game_throw', methods: ['POST'], format: 'json')]
    public function throw(#[AttributeMapEntity(id: 'gameId')] Game $game, GameThrowServiceInterface $gameThrowService, GameServiceInterface $gameService, #[MapRequestPayload] ThrowRequest $dto): mixed
    {
        $gameThrowService->recordThrow($game, $dto);

        $gameDto = $gameService->createGameDto($game);

        return $gameDto;
    }

    /**
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
