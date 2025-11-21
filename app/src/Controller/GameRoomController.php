<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Game;
use App\Entity\User;
use App\Repository\GamePlayersRepository;
use App\Repository\GameRepository;
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
    #[Route(path: 'api/room', name: 'room_list')]
    public function index(GameRepository $gameRepository, GamePlayersRepository $gamePlayersRepository, Request $request): Response
    {
        $page = $request->query->getInt('page', 1);
        $limit = 9;
        $offset = ($page - 1) * $limit;

        $totalGames = $gameRepository->count([]);
        $totalPages = ceil($totalGames / $limit);

        $games = $gameRepository->findBy([], null, $limit, $offset);

        $playerCounts = [];
        foreach ($games as $game) {
            $gameId = $game->getGameId();
            $count = $gamePlayersRepository->count(['gameId' => $gameId]);
            $playerCounts[$gameId] = $count;
        }

        return $this->render('room/list.html.twig', [
            'games' => $games,
            'playerCounts' => $playerCounts,
            'currentPage' => $page,
            'totalPages' => $totalPages,
        ]);
    }

    #[Route(path: 'api/room/waiting', name: 'waiting_room')]
    public function waitingRoom(): Response
    {
        return $this->render('room/waiting.html.twig', []);
    }

    #[Route(path: 'api/room/create', name: 'room_create', methods: ['POST', 'GET'])]
    public function roomCreate(Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            $game = new Game();
            $game->setDate(new \DateTime());

            $entityManager->persist($game);
            $entityManager->flush();

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

    #[Route(path: 'api/room/{id}', name: 'room_details', methods: ['GET'])]
    public function roomDetails(
        int $id,
        GameRepository $gameRepository,
        GamePlayersRepository $gamePlayersRepository,
        Request $request
    ): Response {
        $game = $gameRepository->find($id);
        if (!$game) {
            throw $this->createNotFoundException('Game not found');
        }

        if (str_contains($request->headers->get('Accept', ''), 'application/json')) {
            $players = $gamePlayersRepository->findPlayersWithUserInfo($id);

            return $this->json([
                'success' => true,
                'gameId' => $id,
                'players' => $players,
                'count' => count($players)
            ]);
        }

        $count = $gamePlayersRepository->count(['gameId' => $id]);

        return $this->render('room/detail.html.twig', [
            'game' => $game,
            'count' => $count,
        ]);
    }

    #[Route(path: 'api/room/{id}?playerId={playerId}', name: 'room_update', methods: ['DELETE'])]
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

        // get from current user
        $user = $this->getUser();
        if ($user instanceof User) {
            return $user->getId();
        }

        // Nothing found
        return null;
    }

    #[Route(path: 'api/room/{id}', name: 'room_player_leave', methods: ['DELETE'])]
    public function playerLeave(
        int $id,
        GamePlayersRepository $gamePlayersRepository,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $playerId = $this->resolvePlayerId($request);

        if (null === $playerId) {
            return $this->json([
                'success' => false,
                'message' => 'playerId is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        $gamePlayer = $gamePlayersRepository->findOneBy([
            'gameId' => $id,
            'playerId' => $playerId,
        ]);

        if (null === $gamePlayer) {
            return $this->json([
                'success' => false,
                'message' => 'Player not found in this game',
            ], Response::HTTP_NOT_FOUND);
        }

        $entityManager->remove($gamePlayer);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Player removed from the game',
        ]);
    }



    #[Route(path: 'api/room/{id}/stream', name: 'room_stream', methods: ['GET'])]
    public function roomStream(
        int $id,
        GameRepository $gameRepository,
        GamePlayersRepository $gamePlayersRepository
    ): StreamedResponse {
        $game = $gameRepository->find($id);
        if (!$game) {
            throw $this->createNotFoundException('Game not found');
        }

        $response = new StreamedResponse(function () use ($id, $gamePlayersRepository) {
            set_time_limit(0);
            $eventId = 0;
            $lastPayload = null;

            $sendPayload = function () use (&$eventId, &$lastPayload, $id, $gamePlayersRepository) {
                $players = $gamePlayersRepository->findPlayersWithUserInfo($id);
                $payload = json_encode([
                    'players' => $players,
                    'count' => count($players),
                ]);

                if (false === $payload || $payload === $lastPayload) {
                    return;
                }

                $lastPayload = $payload;
                $eventId++;

                echo 'id: ' . $eventId . "\n";
                echo "event: players\n";
                echo 'data: ' . $payload . "\n\n";
                @ob_flush();
                @flush();
            };

            while (!connection_aborted()) {
                $sendPayload();

                // Heartbeat comment keeps proxies from closing the stream.
                echo ": heartbeat\n\n";
                @ob_flush();
                @flush();
                sleep(1);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }
}
