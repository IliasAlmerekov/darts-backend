<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\GuestPlayerRequest;
use App\Dto\GameSettingsRequest;
use App\Dto\PlayerIdPayload;
use App\Dto\RoomCreateRequest;
use App\Dto\SuccessMessageDto;
use App\Dto\UpdatePlayerOrderRequest;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Exception\Game\GameNotFoundException;
use App\Exception\Game\PlayerNotFoundInGameException;
use App\Exception\Request\PlayerIdRequiredException;
use App\Http\Attribute\ApiResponse;
use App\Service\Game\GameRoomServiceInterface;
use App\Service\Game\GameSettingsServiceInterface;
use App\Service\Game\RematchServiceInterface;
use App\Service\Persistence\RetryingTransactionInterface;
use App\Service\Player\GuestPlayerServiceInterface;
use App\Service\Player\PlayerManagementServiceInterface;
use App\Service\Sse\SseStreamServiceInterface;
use Doctrine\ORM\Exception\ORMException;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
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
     * @param GameSettingsServiceInterface     $gameSettingsService
     * @param RematchServiceInterface          $rematchService
     * @param GuestPlayerServiceInterface      $guestPlayerService
     * @param SseStreamServiceInterface        $sseStreamService
     * @param RetryingTransactionInterface     $transaction
     *
     * @return void
     */
    public function __construct(
        private readonly GameRoomServiceInterface $gameRoomService,
        private readonly PlayerManagementServiceInterface $playerManagementService,
        private readonly GameSettingsServiceInterface $gameSettingsService,
        private readonly RematchServiceInterface $rematchService,
        private readonly GuestPlayerServiceInterface $guestPlayerService,
        private readonly SseStreamServiceInterface $sseStreamService,
        private readonly RetryingTransactionInterface $transaction,
    ) {
    }

    /**
     * Returns the open lobby, or creates one with optional preselected players.
     *
     * @param RoomCreateRequest $dto
     *
     * @return array{success:bool,gameId:int|null}
     *
     * @throws ORMException
     */
    #[OA\Post(
        summary: 'Spielraum erstellen oder die offene Lobby zurückgeben',
        description: 'Es ist höchstens ein Spiel in der Lobby. Ist ein Spiel in der Lobby, liefert der Endpoint dessen `gameId` und legt kein Spiel an. Die Lobby übernimmt `startScore`, `doubleOut` und `tripleOut` aus dem Request und behält ihre Spieler; `playerIds` und `excludePlayerIds` werden dann ignoriert. Ohne offene Lobby wird eine neue Lobby mit den Spielern aus `playerIds` ohne `excludePlayerIds` angelegt. `previousGameId` ist veraltet und wird immer ignoriert.'
    )]
    #[OA\RequestBody(
        required: false,
        content: new OA\JsonContent(ref: new Model(type: RoomCreateRequest::class))
    )]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Die offene Lobby wurde zurückgegeben oder eine neue Lobby angelegt.',
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
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Der Request-Body ist kein gültiges JSON (`REQUEST_INVALID_JSON_BODY`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Der Request-Body ist ungültig und besteht die Validierung nicht, oder das Spiel hätte danach `doubleOut` und `tripleOut` zugleich (`GAME_CONFLICTING_OUT_RULES`). Das Spiel bleibt unverändert.', content: new OA\JsonContent(anyOf: [new OA\Schema(ref: '#/components/schemas/RequestValidationFailed'), new OA\Schema(ref: '#/components/schemas/ApiError')]))]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Angemeldet ohne `ROLE_ADMIN` (z. B. ein Spieler) (`ACCESS_DENIED`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Eine ID in `playerIds` gehört zu keinem Benutzer (`PLAYER_NOT_FOUND`). Es wird keine Lobby angelegt.')]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'Die neue Lobby hätte mehr als 10 Spieler (`GAME_ROOM_FULL`). Es wird keine Lobby angelegt. Eine offene Lobby ignoriert `playerIds`.')]
    #[Route(path: '/api/room/create', name: 'room_create', methods: ['POST'], format: 'json')]
    public function roomCreateApi(#[MapRequestPayload] RoomCreateRequest $dto): array
    {
        return $this->transaction->run(function () use ($dto): array {
            $includePlayerIds = null !== $dto->playerIds && [] !== $dto->playerIds
                ? $dto->playerIds
                : null;
            $excludePlayerIds = null !== $dto->excludePlayerIds && [] !== $dto->excludePlayerIds
                ? $dto->excludePlayerIds
                : null;

            $game = $this->gameRoomService->openRoom($includePlayerIds, $excludePlayerIds);

            $settingsRequest = $this->createGameSettingsRequest($dto);
            if (null !== $settingsRequest) {
                $this->gameSettingsService->applySettings($game, $settingsRequest);
            }

            return ['success' => true, 'gameId' => $game->getGameId()];
        });
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
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'playerId fehlt oder ist ungültig. Ist der Request-Body kein gültiges JSON, antwortet der Endpoint ebenfalls mit 400 (`REQUEST_INVALID_JSON_BODY`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Spieler wurde im Spiel nicht gefunden.')]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'Das Spiel ist nicht mehr in der Lobby (`GAME_SEATS_NOT_EDITABLE`). Der Spieler bleibt im Spiel.')]
    #[ApiResponse]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Der Request-Body ist ungültig und besteht die Validierung nicht. Das wird vor dem Status geprüft, also auch außerhalb der Lobby mit 422 statt 409 beantwortet.', content: new OA\JsonContent(ref: '#/components/schemas/RequestValidationFailed'))]
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
     * Adds a guest player to the room.
     *
     * @param int                $id
     * @param GuestPlayerRequest $dto
     *
     * @return Response
     */
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: GuestPlayerRequest::class)))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Gast-Spieler wurde dem Spiel hinzugefügt.',
        content: new OA\JsonContent(
            type: 'object',
            required: ['success', 'player'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'player',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 123),
                        new OA\Property(property: 'name', type: 'string', example: 'Alex'),
                        new OA\Property(property: 'position', type: 'integer', nullable: true, example: 2),
                        new OA\Property(property: 'placement', type: 'integer', nullable: true, example: null),
                        new OA\Property(property: 'isGuest', type: 'boolean', example: true),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: Response::HTTP_CONFLICT,
        description: 'Username ist bereits vergeben (`USERNAME_TAKEN`), das Spiel ist nicht mehr in der Lobby (`GAME_SEATS_NOT_EDITABLE`) oder hat schon 10 Spieler (`GAME_ROOM_FULL`). Geprüft wird in dieser Reihenfolge: Status, volle Lobby, Name. Es wird weder ein Gast noch ein Platz angelegt.',
        content: new OA\JsonContent(
            type: 'object',
            required: ['success', 'error', 'message'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'error', type: 'string', example: 'USERNAME_TAKEN'),
                new OA\Property(property: 'message', type: 'string', example: 'The name is already taken'),
                new OA\Property(
                    property: 'suggestions',
                    type: 'array',
                    items: new OA\Items(type: 'string'),
                    example: ['Alex 2', 'Alex 3', 'Alex 4']
                ),
            ]
        )
    )]
    #[ApiResponse]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Der Request-Body ist kein gültiges JSON (`REQUEST_INVALID_JSON_BODY`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Der Request-Body ist ungültig und besteht die Validierung nicht. Das wird vor dem Status geprüft, also auch außerhalb der Lobby mit 422 statt 409 beantwortet.', content: new OA\JsonContent(ref: '#/components/schemas/RequestValidationFailed'))]
    #[Route(path: '/api/room/{id}/guest', name: 'room_player_guest_add', methods: ['POST'], format: 'json')]
    public function addGuestPlayer(int $id, #[MapRequestPayload] GuestPlayerRequest $dto): Response
    {
        $game = $this->gameRoomService->findGameById($id);
        if (!$game) {
            throw new GameNotFoundException();
        }

        $player = $this->guestPlayerService->createGuestPlayer($game, (string) $dto->username);

        return $this->json([
            'success' => true,
            'player' => [
                'id' => $player['playerId'],
                'name' => $player['name'],
                'position' => $player['position'],
                'placement' => $player['placement'],
                'isGuest' => $player['isGuest'],
            ],
        ]);
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
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'Das Spiel ist nicht mehr in der Lobby (`GAME_SEATS_NOT_EDITABLE`), auch bei leerem `positions`. Die Positionen bleiben unverändert.')]
    #[ApiResponse]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Der Request-Body ist kein gültiges JSON (`REQUEST_INVALID_JSON_BODY`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Der Request-Body ist ungültig und besteht die Validierung nicht. Das wird vor dem Status geprüft, also auch außerhalb der Lobby mit 422 statt 409 beantwortet.', content: new OA\JsonContent(ref: '#/components/schemas/RequestValidationFailed'))]
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
     * Streams the players of a room in the lobby; outside the lobby answers 204 (ADR 0001).
     *
     * @param int     $id
     * @param Request $request
     *
     * @return Response
     */
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Server-Sent Events (SSE) Stream für die Lobby. Sendet `players`-Events (`players`, `count`), wenn sich die Spieler ändern, und Heartbeats. Der Stream endet spätestens eine Sekunde, nachdem das Spiel die Lobby verlässt (gestartet oder abgebrochen), und spätestens nach 5 Minuten. Würfe sendet der Stream nicht.',
        content: new OA\MediaType(mediaType: 'text/event-stream')
    )]
    #[OA\Response(response: Response::HTTP_NO_CONTENT, description: 'Das Spiel ist nicht in der Lobby (gestartet, beendet oder abgebrochen). Ohne Body, damit EventSource nicht neu verbindet.')]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Spiel nicht gefunden.')]
    #[Route(path: '/api/room/{id}/stream', name: 'room_stream', methods: ['GET'])]
    public function roomStream(int $id, Request $request): Response
    {
        if ($request->hasSession()) {
            $request->getSession()->save();
        }

        $game = $this->gameRoomService->findGameById($id);
        if (!$game) {
            throw new GameNotFoundException();
        }
        if (GameStatus::Lobby !== $game->getStatus()) {
            return new Response(null, Response::HTTP_NO_CONTENT);
        }

        return $this->sseStreamService->createPlayerStream($id);
    }

    /**
     * Returns the open lobby, or a new one, with the players and settings of a finished game.
     *
     * @param int $id
     *
     * @return array<string, mixed>
     *
     * @throws ORMException
     */
    #[OA\Post(
        summary: 'Rematch in der offenen Lobby oder in einer neuen Lobby',
        description: 'Es ist höchstens ein Spiel in der Lobby. Ist ein Spiel in der Lobby, liefert der Endpoint dessen `gameId`: Spieler des alten Spiels, die noch fehlen, kommen hinter die vorhandenen Spieler, und Startpunktzahl, Double Out, Triple Out und Typ werden aus dem alten Spiel übernommen. Spieler, die der Lobby beigetreten sind, bleiben. Ohne offene Lobby wird eine neue Lobby mit den Spielern des alten Spiels angelegt.'
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\Response(
        response: Response::HTTP_CREATED,
        description: 'Die offene Lobby wurde zurückgegeben oder eine neue Lobby angelegt.',
        content: new OA\JsonContent(
            required: ['success'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'gameId', type: 'integer', example: 456, nullable: true),
                new OA\Property(property: 'invitationLink', type: 'string', example: '/api/invite/join/xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx', nullable: true),
                new OA\Property(property: 'message', type: 'string', example: 'Vorheriges Spiel nicht gefunden', nullable: true),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Vorheriges Spiel nicht gefunden (`GAME_NOT_FOUND`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[ApiResponse(status: Response::HTTP_CREATED)]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: `/api` ist per Firewall nur für ROLE_ADMIN offen, und der Code prüft zusätzlich die Spielteilnahme (`ACCESS_DENIED`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'Das alte Spiel ist nicht das neueste Spiel, oder es ist weder beendet noch abgebrochen (`GAME_NOT_NEWEST`). Eine offene Lobby ist immer das neueste Spiel.')]
    #[Route(path: '/api/room/{id}/rematch', name: 'room_rematch', methods: ['POST'])]
    public function rematch(int $id): array
    {
        $result = $this->transaction->run(fn (): array => $this->rematchService->createRematch($id));

        if (!($result['success'] ?? false)) {
            throw new GameNotFoundException();
        }

        return $result;
    }

    /**
     * Builds a game settings DTO only when room-create includes settings fields.
     *
     * @param RoomCreateRequest $dto
     *
     * @return GameSettingsRequest|null
     */
    private function createGameSettingsRequest(RoomCreateRequest $dto): ?GameSettingsRequest
    {
        if (null === $dto->startScore && null === $dto->doubleOut && null === $dto->tripleOut) {
            return null;
        }

        return new GameSettingsRequest(
            startScore: $dto->startScore,
            doubleOut: $dto->doubleOut,
            tripleOut: $dto->tripleOut,
        );
    }
}
