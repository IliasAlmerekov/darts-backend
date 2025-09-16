<?php

namespace App\Controller;

use App\Entity\Game;
use App\Repository\GameRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;

class GameRoomController extends AbstractController
{
    #[Route(path: '/room', name: 'room_list')]
    public function index(GameRepository $gameRepository): Response
    {
        $games = $gameRepository->findAll();

        return $this->render('room/list.html.twig', [
            'games' => $games,
        ]);
    }

    #[Route(path: '/room/create', name: 'room_create', methods: ['POST', 'GET'])]
    public function roomCreate(Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            $game = new Game();
            $game->setDate(new \DateTime());
            $entityManager->persist($game);
            $entityManager->flush();

            return $this->redirectToRoute('create_invitation', ['id' => $game->getGameId()]);
        }

        return $this->render('room/create.html.twig', []);
    }

    #[Route(path: '/room/{id}', name: 'room_details', methods: ['GET'])]
    public function roomDetails(int $id, GameRepository $gameRepository): Response
    {
        $game = $gameRepository->findBy(['gameId' => $id]);

        return $this->render('room/detail.html.twig', [
            'game' => $game,
        ]);
    }
}
