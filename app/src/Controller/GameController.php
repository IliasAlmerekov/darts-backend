<?php

namespace App\Controller;

use App\Dto\StartGameRequest;
use App\Service\GameStartService;
use InvalidArgumentException;
use App\Repository\GameRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class GameController extends AbstractController
{
    #[Route('/api/game/{gameId}/start', name: 'app_game_start', methods: ['POST'])]
    public function start(
        int $gameId,
        #[MapRequestPayload] StartGameRequest $dto,
        GameRepository $gameRepository,
        GameStartService $gameStartService,
    ): Response {
        $game = $gameRepository->find($gameId);

        if (!$game) {
            return $this->json(
                ['error' => 'Game not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        try {
            $gameStartService->start($game, $dto);
        } catch (InvalidArgumentException $e) {
            return $this->json(
                ['error' => $e->getMessage()],
                Response::HTTP_BAD_REQUEST
            );
        }

        return $this->json($game, context: ['groups' => 'game:read']);
    }
}


//    /**
//     * @throws \JsonException
//     */
//    #[Route('/api/game/{gameId}/throw', name: 'app_game_start', methods: ['POST'])]
//    public function throw(
//        int                    $gameId,
//        GameRepository         $gameRepository,
//        Request                $request,
//        EntityManagerInterface $entityManager,
//        RoundThrowsRepository  $roundThrowsRepository,
//    ): Response
//    {
//        $game = $gameRepository->find($gameId);
//
//        if (!$game) {
//            return $this->json(
//                ['error' => 'Game not found'],
//                Response::HTTP_NOT_FOUND
//            );
//        }
//
//        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
//
//        if (!isset($data['player_id'], $data['value'])) {
//            return $this->json(
//                ['error' => 'Missing required fields: player_id, value'],
//                Response::HTTP_BAD_REQUEST
//            );
//        }
//
//        $playerId = $data['player_id'];
//        $value = $data['value'];
//        $isDouble = $data['is_double'] ?? false;
//        $isTriple = $data['is_triple'] ?? false;
//        $isBust = $data['is_bust'] ?? false;
//
//        $score = $value;
//        if ($isTriple) {
//            $score = $value * 3;
//        } elseif ($isDouble) {
//            $score = $value * 2;
//        }
//        if ($isBust) {
//            $score = 0;
//        }
//
//
//        $roundThrows = $roundThrowsRepository->find($playerId);
//        if     (!$roundThrows) {
//            return $this->json(
//                ['error' => 'Player not found in this game'],
//                Response::HTTP_NOT_FOUND
//            );
//        }
//        $roundThrows->setValue($value);
//        $roundThrows->setIsDouble($isDouble);
//        $roundThrows->setIsTriple($isTriple);
//        $roundThrows->setIsBust($isBust);
//        $entityManager->flush();
//    }
