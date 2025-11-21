<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use App\Repository\GameRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GameController extends AbstractController
{
    /**
     * @throws \JsonException
     */
    #[Route('/api/game/{gameId}', name: 'app_game_start', methods: ['POST'])]
    public function index(
        int $gameId,
        GameRepository $gameRepository,
        Request $request,
    ): Response
    {
        $game = $gameRepository->findOneByGameId($gameId);
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        if ($game && $data) {
            $game->setStatus($data['status']);
            $game->setRound($data['round']);
        }

        return $this->json($game, context: ['groups' => 'game:read']);
    }
}
