<?php declare(strict_types=1);

namespace App\Controller;

use App\Entity\Game;
use App\Repository\GamePlayersRepository;
use App\Repository\GameRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;

/**
 * This class handles game room related actions such as listing rooms,
 * creating rooms, and viewing room details.
 * also get as JSON responses for API requests.
 */
class GameRoomController extends AbstractController
{
    #[Route(path: '/room', name: 'room_list')]
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
    
    #[Route(path: '/room/waiting', name: 'waiting_room')]
    public function waitingRoom(): Response
    {
        return $this->render('room/waiting.html.twig', []);
    }

    #[Route(path: '/room/create', name: 'room_create', methods: ['POST', 'GET'])]
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

        return $this->render('room', []);
    }

    #[Route(path: '/room/{id}', name: 'room_details', methods: ['GET'])]
    public function roomDetails(
        int $id,
        GameRepository $gameRepository,
        GamePlayersRepository $gamePlayersRepository,
        Request $request
    ): Response
    {
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
}
