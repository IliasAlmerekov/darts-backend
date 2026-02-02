<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\PlayerIdPayload;
use App\Dto\RoomCreateRequest;
use App\Dto\SuccessMessageDto;
use App\Dto\UpdatePlayerOrderRequest;
use App\Entity\User;
use App\Exception\Game\GameNotFoundException;
use App\Exception\Game\PlayerNotFoundInGameException;
use App\Exception\Request\PlayerIdRequiredException;
use App\Http\Attribute\ApiResponse;
use App\Service\Game\GameRoomServiceInterface;
use App\Service\Game\RematchServiceInterface;
use App\Service\Player\PlayerManagementServiceInterface;
use App\Service\Sse\SseStreamServiceInterface;
use Doctrine\ORM\Exception\ORMException;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * This class handles game-room-related actions such as listing rooms,
 * creating rooms, and viewing room details.
 * Also get as JSON responses for API requests.
 */
#[OA\Tag(name: 'Game Rooms')]
final class GameRoomController extends AbstractController
{
    /**
     * @param GameRoomServiceInterface         $gameRoomService
     * @param PlayerManagementServiceInterface $playerManagementService
     * @param RematchServiceInterface          $rematchService
     * @param SseStreamServiceInterface        $sseStreamService
     *
     * @return void
     */
    public function __construct(
        private readonly GameRoomServiceInterface $gameRoomService,
        private readonly PlayerManagementServiceInterface $playerManagementService,
        private readonly RematchServiceInterface $rematchService,
        private readonly SseStreamServiceInterface $sseStreamService,
    ) {
    }

    /**
     * Creates a game with optional preselected players.
     *
     * @param RoomCreateRequest $dto
     *
     * @return array{success:bool,gameId:int|null}
     *
     * @throws ORMException
     */
    #[OA\RequestBody(
        required: false,
        content: new OA\JsonContent(ref: new Model(type: RoomCreateRequest::class))
    )]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Spielraum wurde erfolgreich erstellt (oder wiederverwendet).',
        content: new OA\JsonContent(
            required: ['success', 'gameId'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'gameId', type: 'integer', example: 123, nullable: true),
            ],
            type: 'object'
        )
    )]
    #[ApiResponse]
    #[Route(path: '/api/room/create', name: 'room_create', methods: ['POST'], format: 'json')]
    public function roomCreateApi(#[MapRequestPayload] RoomCreateRequest $dto): array
    {
        $game = $this->gameRoomService->createGameWithPreviousPlayers(
            $dto->previousGameId ?: null,
            $dto->playerIds ? array_values(array_map('intval', $dto->playerIds)) : null,
            $dto->excludePlayerIds ? array_values(array_map('intval', $dto->excludePlayerIds)) : null,
        );

        return ['success' => true, 'gameId' => $game->getGameId()];
    }

    /**
     * Removes a player from the room.
     *
     * @param int                  $id
     * @param int|null             $playerId
     * @param PlayerIdPayload|null $payload
     *
     * @return SuccessMessageDto
     */
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\Parameter(
        name: 'playerId',
        description: 'Spieler-ID, die entfernt werden soll. Wenn nicht angegeben, wird der authentifizierte User oder der Request-Body verwendet.',
        in: 'query',
        required: false,
        schema: new OA\Schema(type: 'integer', example: 42, nullable: true)
    )]
    #[OA\RequestBody(
        description: 'Alternative Übergabe der playerId (falls nicht als Query-Parameter).',
        required: false,
        content: new OA\JsonContent(ref: new Model(type: PlayerIdPayload::class))
    )]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Spieler wurde aus dem Spiel entfernt.',
        content: new OA\JsonContent(ref: new Model(type: SuccessMessageDto::class))
    )]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'playerId fehlt oder ist ungültig.')]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Spieler wurde im Spiel nicht gefunden.')]
    #[ApiResponse]
    #[Route(path: '/api/room/{id}', name: 'room_player_leave', methods: ['DELETE'], format: 'json')]
    public function playerLeave(int $id, #[MapQueryParameter] ?int $playerId = null, #[MapRequestPayload] ?PlayerIdPayload $payload = null): SuccessMessageDto
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            $playerId ??= $user->getId();
        }
        $playerId ??= $payload?->playerId;
        if (null === $playerId || $playerId <= 0) {
            throw new PlayerIdRequiredException();
        }

        $removed = $this->playerManagementService->removePlayer($id, $playerId);
        if (!$removed) {
            throw new PlayerNotFoundInGameException();
        }

        return new SuccessMessageDto('Player removed from the game');
    }

    /**
     * Updates the positions of players in a room.
     *
     * @param int                      $id
     * @param UpdatePlayerOrderRequest $dto
     *
     * @return array{success:bool,message?:string}
     */
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdatePlayerOrderRequest::class)))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Spielerpositionen wurden aktualisiert.',
        content: new OA\JsonContent(
            required: ['success'],
            properties: [new OA\Property(property: 'success', type: 'boolean', example: true)],
            type: 'object'
        )
    )]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Spiel nicht gefunden.')]
    #[ApiResponse]
    #[Route(path: '/api/room/{id}/positions', name: 'room_update_player_positions', methods: ['POST'], format: 'json')]
    public function updatePlayerOrder(int $id, #[MapRequestPayload] UpdatePlayerOrderRequest $dto): array
    {
        $game = $this->gameRoomService->findGameById($id);
        if (!$game) {
            throw new GameNotFoundException();
        }

        $this->playerManagementService->updatePlayerPositions($id, $dto->positions);

        return ['success' => true];
    }

    /**
     * Streams SSE updates for a room.
     *
     * @param int     $id
     * @param Request $request
     *
     * @return StreamedResponse
     */
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Server-Sent Events (SSE) Stream für Raum-Updates.',
        content: new OA\MediaType(mediaType: 'text/event-stream')
    )]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Spiel nicht gefunden.')]
    #[Route(path: '/api/room/{id}/stream', name: 'room_stream', methods: ['GET'])]
    public function roomStream(int $id, Request $request): StreamedResponse
    {
        if ($request->hasSession()) {
            $request->getSession()->save();
        }

        $game = $this->gameRoomService->findGameById($id);
        if (!$game) {
            throw new GameNotFoundException();
        }

        return $this->sseStreamService->createPlayerStream($id);
    }

    /**
     * Creates a rematch for a finished game.
     *
     * @param int $id
     *
     * @return array<string, mixed>
     *
     * @throws ORMException
     */
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\Response(
        response: Response::HTTP_CREATED,
        description: 'Rematch-Spiel wurde erstellt.',
        content: new OA\JsonContent(
            required: ['success'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'gameId', type: 'integer', example: 456, nullable: true),
                new OA\Property(property: 'invitationLink', type: 'string', example: '/api/invite/join/xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx', nullable: true),
                new OA\Property(
                    property: 'finishedPlayers',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'playerId', type: 'integer', example: 1, nullable: true),
                            new OA\Property(property: 'username', type: 'string', example: 'alice', nullable: true),
                            new OA\Property(property: 'position', type: 'integer', example: 1, nullable: true),
                            new OA\Property(property: 'roundsPlayed', type: 'integer', example: 10, nullable: true),
                            new OA\Property(property: 'roundAverage', type: 'number', format: 'float', example: 54.2),
                        ],
                        type: 'object'
                    )
                ),
                new OA\Property(property: 'message', type: 'string', example: 'Vorheriges Spiel nicht gefunden', nullable: true),
                new OA\Property(property: 'status', type: 'integer', example: 404, nullable: true),
            ],
            type: 'object'
        )
    )]
    #[ApiResponse(status: Response::HTTP_CREATED)]
    #[Route(path: '/api/room/{id}/rematch', name: 'room_rematch', methods: ['POST'])]
    public function rematch(int $id): array
    {
        $result = $this->rematchService->createRematch($id);

        if (!($result['success'] ?? false)) {
            $result['status'] = Response::HTTP_NOT_FOUND;
        }

        return $result;
    }
}
