<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ThrowAckDto;
use App\Dto\ThrowRequest;
use App\Dto\UndoAckDto;
use App\Entity\Game;
use App\Exception\Game\GameNotFoundException;
use App\Http\Attribute\ApiResponse;
use App\Service\Game\GameDeltaServiceInterface;
use App\Service\Game\GameThrowServiceInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bridge\Doctrine\Attribute\MapEntity as AttributeMapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints for recording and undoing throws.
 */
#[OA\Tag(name: 'Game Throws')]
final class GameThrowController extends AbstractController
{
    /**
     * Records a throw and returns compact delta payload.
     *
     * @param int                       $gameId
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
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Der Request-Body ist kein gültiges JSON (`REQUEST_INVALID_JSON_BODY`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Der Request-Body ist ungültig und besteht die Validierung nicht.', content: new OA\JsonContent(ref: '#/components/schemas/RequestValidationFailed'))]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: `/api` ist per Firewall nur für ROLE_ADMIN offen, und der Code prüft zusätzlich die Spielteilnahme (`ACCESS_DENIED`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[Route('/api/game/{gameId}/throw/delta', name: 'app_game_throw_delta', methods: ['POST'], format: 'json')]
    public function throwDelta(int $gameId, GameThrowServiceInterface $gameThrowService, GameDeltaServiceInterface $gameDeltaService, #[MapRequestPayload] ThrowRequest $dto): ThrowAckDto
    {
        try {
            $throwRecordingResult = $gameThrowService->recordThrowByGameId($gameId, $dto);
        } catch (GameNotFoundException) {
            throw new NotFoundHttpException();
        }

        return $gameDeltaService->buildThrowAck($throwRecordingResult->game, $throwRecordingResult->latestThrow, $throwRecordingResult->currentRoundStateSnapshot);
    }

    /**
     * Undoes the last recorded throw and returns compact delta payload.
     *
     * @param Game                      $game
     * @param GameThrowServiceInterface $gameThrowService
     * @param GameDeltaServiceInterface $gameDeltaService
     *
     * @return UndoAckDto
     */
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Kompakter Delta-Ack nach dem Rückgängigmachen des letzten Wurfs.',
        content: new OA\JsonContent(ref: new Model(type: UndoAckDto::class))
    )]
    #[ApiResponse]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: `/api` ist per Firewall nur für ROLE_ADMIN offen, und der Code prüft zusätzlich die Spielteilnahme (`ACCESS_DENIED`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'Das Spiel ist in der Lobby oder abgebrochen (`GAME_THROW_NOT_ALLOWED`); es wird nichts gelöscht. Oder der letzte Wurf hat das Spiel beendet, und das Spiel ist nicht das neueste Spiel (`GAME_NOT_NEWEST`). Das Rückgängigmachen würde das laufende Spiel abbrechen.')]
    #[Route('/api/game/{gameId}/throw/delta', name: 'app_game_throw_undo_delta', methods: ['DELETE'], format: 'json')]
    public function undoThrowDelta(#[AttributeMapEntity(id: 'gameId')] Game $game, GameThrowServiceInterface $gameThrowService, GameDeltaServiceInterface $gameDeltaService): UndoAckDto
    {
        $undoneThrow = $gameThrowService->undoLastThrow($game);

        return $gameDeltaService->buildUndoAck($game, $undoneThrow);
    }
}
