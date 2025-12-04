<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\GameRoomService;
use App\Service\PlayerManagementService;
use App\Service\RematchService;
use App\Service\SseStreamService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;

/**
 * This class handles game-room-related actions such as listing rooms,
 * creating rooms, and viewing room details.
 * Also get as JSON responses for API requests.
 */
final class GameRoomController extends AbstractController
{
    /**
     * @param GameRoomService          $gameRoomService
     * @param PlayerManagementService  $playerManagementService
     * @param RematchService           $rematchService
     * @param SseStreamService         $sseStreamService
     */
    public function __construct(
        private readonly GameRoomService $gameRoomService,
        private readonly PlayerManagementService $playerManagementService,
        private readonly RematchService $rematchService,
        private readonly SseStreamService $sseStreamService
    ) {
    }

    #[Route(path: 'api/room/create', name: 'room_create', methods: ['POST', 'GET'])]
    /**
     * @param Request $request
     *
     * @return Response
     */
    public function roomCreate(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $payload = json_decode($request->getContent(), true);
            $previousGameId = null;
            $selectedPlayers = null;
            $excludedPlayers = null;
            if (is_array($payload) && isset($payload['previousGameId'])) {
                $previousGameId = (int) $payload['previousGameId'];
                if (isset($payload['playerIds']) && is_array($payload['playerIds'])) {
                    $selectedPlayers = array_values(array_filter(array_map('intval', $payload['playerIds'])));
                }
                if (isset($payload['excludePlayerIds']) && is_array($payload['excludePlayerIds'])) {
                    $excludedPlayers = array_values(array_filter(array_map('intval', $payload['excludePlayerIds'])));
                }
            } elseif ($request->query->has('previousGameId')) {
                $previousGameId = $request->query->getInt('previousGameId');
            }

            $game = $this->gameRoomService->createGameWithPreviousPlayers(
                $previousGameId !== 0 ? $previousGameId : null,
                $selectedPlayers,
                $excludedPlayers
            );
            if (str_contains($request->headers->get('Accept') ?? '', 'application/json')) {
                return $this->json([
                    'success' => true,
                    'gameId' => $game->getGameId(),
                ]);
            }

            return $this->redirectToRoute('create_invitation', ['id' => $game->getGameId()]);
        }

        return $this->render('room/create.html.twig');
    }

    #[Route(path: '/api/room/{id}', name: 'room_player_leave', methods: ['DELETE'])]
    /**
     * @param int     $id
     * @param Request $request
     *
     * @return Response
     */
    public function playerLeave(int $id, Request $request): Response
    {
        $playerId = $this->resolvePlayerId($request);
        if (null === $playerId) {
            return $this->json([
                'success' => false,
                'message' => 'playerId is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        $removed = $this->playerManagementService->removePlayer($id, $playerId);
        if (!$removed) {
            return $this->json([
                'success' => false,
                'message' => 'Player not found in this game',
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'message' => 'Player removed from the game',
        ]);
    }

    #[Route(path: 'api/room/{id}/stream', name: 'room_stream', methods: ['GET'])]
    /**
     * @param int     $id
     * @param Request $request
     *
     * @return StreamedResponse
     */
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

    #[Route(path: 'api/room/{id}/rematch', name: 'room_rematch', methods: ['POST'])]
    /**
     * @param int $id
     *
     * @return Response
     */
    public function rematch(int $id): Response
    {
        $result = $this->rematchService->createRematch($id);
        return $this->json($result, $result['success'] ? Response::HTTP_CREATED : Response::HTTP_NOT_FOUND);
    }

    /**
     * @param Request $request
     *
     * @return int|null
     */
    private function resolvePlayerId(Request $request): ?int
    {
        // get playerId from various sources
        $playerId = $request->query->getInt('playerId');
        if ($playerId > 0) {
            return $playerId;
        }

        // get from JSON body
        $payload = json_decode($request->getContent(), true);
        if (is_array($payload) && isset($payload['playerId'])) {
            $playerId = (int) $payload['playerId'];
            if ($playerId > 0) {
                return $playerId;
            }
        }

        // get from the current user
        $user = $this->getUser();
        if ($user instanceof User) {
            return $user->getId();
        }

        // Nothing found
        return null;
    }
}
