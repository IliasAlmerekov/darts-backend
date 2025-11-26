<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\GameRoomService;
use App\Service\PlayerManagementService;
use App\Service\RematchService;
use App\Service\SseStreamService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;


/**
 * This class handles game room related actions such as listing rooms,
 * creating rooms, and viewing room details.
 * also get as JSON responses for API requests.
 */
class GameRoomController extends AbstractController
{
    public function __construct(
        private GameRoomService         $gameRoomService,
        private PlayerManagementService $playerManagementService,
        private RematchService          $rematchService,
        private SseStreamService        $sseStreamService
    )
    {
    }

    #[Route(path: 'api/room/create', name: 'room_create', methods: ['POST', 'GET'])]
    public function roomCreate(Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            $game = $this->gameRoomService->createGame();

            if (str_contains($request->headers->get('Accept', ''), 'application/json')) {
                return $this->json([
                    'success' => true,
                    'gameId' => $game->getGameId()
                ]);
            }

            return $this->redirectToRoute('create_invitation', ['id' => $game->getGameId()]);
        }

        return $this->render('room/create.html.twig', []);
    }

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
            $playerId = (int)$payload['playerId'];
            if ($playerId > 0) {
                return $playerId;
            }
        }

        // get from current user
        $user = $this->getUser();
        if ($user instanceof User) {
            return $user->getId();
        }

        // Nothing found
        return null;
    }

    #[Route(path: '/api/room/{id}', name: 'room_player_leave', methods: ['DELETE'])]
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
    public function rematch(int $id): Response
    {
        $result = $this->rematchService->createRematch($id);

        return $this->json(
            $result,
            $result['success'] ? Response::HTTP_CREATED : Response::HTTP_NOT_FOUND
        );
    }
}
