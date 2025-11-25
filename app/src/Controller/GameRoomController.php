<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\Invitation;
use App\Entity\User;
use App\Repository\GamePlayersRepository;
use App\Repository\GameRepository;
use App\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

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

    #[Route(path: '/api/room/{id}', name: 'room_player_leave', methods: ['DELETE'])]
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
            ], Response::HTTP_BAD_REQUEST, ['X-Accel-Buffering' => 'no']);
        }

        $gamePlayer = $gamePlayersRepository->findOneBy([
            'gameId' => $id,
            'playerId' => $playerId,
        ]);

        if (null === $gamePlayer) {
            return $this->json([
                'success' => false,
                'message' => 'Player not found in this game',
            ], Response::HTTP_NOT_FOUND, ['X-Accel-Buffering' => 'no']);
        }

        $entityManager->remove($gamePlayer);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Player removed from the game',
        ], Response::HTTP_OK, ['X-Accel-Buffering' => 'no']);
    }



    #[Route(path: 'api/room/{id}/stream', name: 'room_stream', methods: ['GET'])]
    public function roomStream(
        int $id,
        GameRepository $gameRepository,
        GamePlayersRepository $gamePlayersRepository,
        Request $request
    ): StreamedResponse {
        // Release the session lock so other requests from the same user are not blocked by this long-lived stream
        if ($request->hasSession()) {
            $request->getSession()->save();
        }

        $game = $gameRepository->find($id);
        if (!$game) {
            throw $this->createNotFoundException('Game not found');
        }

        $response = new StreamedResponse(function () use ($id, $gamePlayersRepository) {
            set_time_limit(0);
            $eventId = 0;
            $lastPayload = null;

            // Send an early line to push headers through proxy buffers (SSE needs immediate flush)
            echo ": init\n\n";
            @ob_flush();
            @flush();

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
        // Disable nginx/fastcgi buffering so events are sent immediately
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    #[Route(path: 'api/room/{id}/rematch', name: 'room_rematch', methods: ['POST'])]
public function rematch(
    int $id,
        GameRepository $gameRepository,
        GamePlayersRepository $gamePlayersRepository,
        InvitationRepository $invitationRepository,
        EntityManagerInterface $entityManager,
        Request $request,
        UrlGeneratorInterface $urlGenerator
    ): Response {
        // search old game
        $oldGame = $gameRepository->find($id);
        if (!$oldGame) {
            return $this->json([
                'success' => false,
                'message' => 'Previous game not found',
            ], Response::HTTP_NOT_FOUND);
        }

        // create a new game
        $newGame = new Game();
        $newGame->setDate(new \DateTime());

        $entityManager->persist($newGame);
        $entityManager->flush();

        $newGameId = $newGame->getGameId();

        // copy players from old game to new game
        $oldGamePlayers = $gamePlayersRepository->findBy(['gameId' => $id]);

        foreach ($oldGamePlayers as $oldGamePlayer) {
            $newGamePlayer = new GamePlayers();
            $newGamePlayer->setGameId($newGameId);
            $newGamePlayer->setPlayerId($oldGamePlayer->getPlayerId());

            $entityManager->persist($newGamePlayer);
        }

        $entityManager->flush();

        // create invite for a new game
        $invitation = $invitationRepository->findOneBy(['gameId' => $newGameId]);
        if (null === $invitation) {
            $uuid = Uuid::v4();

            $invitation = new Invitation();
            $invitation->setUuid($uuid);
            $invitation->setGameId($newGameId);

            $entityManager->persist($invitation);
            $entityManager->flush();
        }

        $invitationLink = $urlGenerator->generate(
            'join_invitation',
            ['uuid' => $invitation->getUuid()],
            UrlGeneratorInterface::ABSOLUTE_PATH
        );

        return $this->json([
            'success' => true,
            'gameId' => $newGameId,
            'invitationLink' => $invitationLink,
        ], Response::HTTP_CREATED);
    }
}
