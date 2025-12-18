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
use App\Dto\UpdatePlayerOrderRequest;
use App\Entity\User;
use App\Service\Game\GameRoomServiceInterface;
use App\Service\Game\RematchServiceInterface;
use App\Service\Player\PlayerManagementServiceInterface;
use App\Service\Sse\SseStreamServiceInterface;
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
     * @return Response
     */
    #[Route(path: '/api/room/create', name: 'room_create', methods: ['POST'], format: 'json')]
    public function roomCreateApi(#[MapRequestPayload] RoomCreateRequest $dto): Response
    {
        $game = $this->gameRoomService->createGameWithPreviousPlayers(
            $dto->previousGameId ?: null,
            $dto->playerIds ? array_values(array_map('intval', $dto->playerIds)) : null,
            $dto->excludePlayerIds ? array_values(array_map('intval', $dto->excludePlayerIds)) : null,
        );

        return $this->json(['success' => true, 'gameId' => $game->getGameId()]);
    }

    /**
     * Removes a player from the room.
     *
     * @param int                  $id
     * @param int|null             $playerId
     * @param PlayerIdPayload|null $payload
     *
     * @return Response
     */
    #[Route(path: '/api/room/{id}', name: 'room_player_leave', methods: ['DELETE'], format: 'json')]
    public function playerLeave(int $id, #[MapQueryParameter] ?int $playerId = null, #[MapRequestPayload] ?PlayerIdPayload $payload = null): Response
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            $playerId ??= $user->getId();
        }
        $playerId ??= $payload?->playerId;
        if (null === $playerId || $playerId <= 0) {
            return $this->json(
                [
                    'success' => false,
                    'message' => 'Player ID is required',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }

        $removed = $this->playerManagementService->removePlayer($id, $playerId);
        if (!$removed) {
            return $this->json(
                [
                    'success' => false,
                    'message' => 'Player not found in this game',
                ],
                Response::HTTP_NOT_FOUND
            );
        }

        return $this->json(
            [
                'success' => true,
                'message' => 'Player removed from the game',
            ]
        );
    }

    /**
     * Updates the positions of players in a room.
     *
     * @param int                      $id
     * @param UpdatePlayerOrderRequest $dto
     *
     * @return Response
     */
    #[Route(path: '/api/room/{id}/positions', name: 'room_update_player_positions', methods: ['POST'], format: 'json')]
    public function updatePlayerOrder(int $id, #[MapRequestPayload] UpdatePlayerOrderRequest $dto): Response
    {
        $game = $this->gameRoomService->findGameById($id);
        if (!$game) {
            return $this->json(['success' => false, 'message' => 'Game not found'], Response::HTTP_NOT_FOUND);
        }

        $this->playerManagementService->updatePlayerPositions($id, $dto->positions);

        return $this->json(['success' => true]);
    }

    /**
     * Streams SSE updates for a room.
     *
     * @param int     $id
     * @param Request $request
     *
     * @return StreamedResponse
     */
    #[Route(path: '/api/room/{id}/stream', name: 'room_stream', methods: ['GET'])]
    public function roomStream(int $id, Request $request): StreamedResponse
    {
        if ($request->hasSession()) {
            $request->getSession()->save();
        }

        $game = $this->gameRoomService->findGameById($id);
        if (!$game) {
            throw $this->createNotFoundException('Game not found');
        }

        return $this->sseStreamService->createPlayerStream($id);
    }

    /**
     * Creates a rematch for a finished game.
     *
     * @param int $id
     *
     * @return Response
     */
    #[Route(path: '/api/room/{id}/rematch', name: 'room_rematch', methods: ['POST'])]
    public function rematch(int $id): Response
    {
        $result = $this->rematchService->createRematch($id);

        return $this->json($result, $result['success'] ? Response::HTTP_CREATED : Response::HTTP_NOT_FOUND);
    }
}
