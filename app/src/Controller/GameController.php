<?php

namespace App\Controller;

use App\Dto\UpdateGameDto;
use App\Entity\RoundThrows;
use App\Enum\GameStatus;
use App\Repository\RoundThrowsRepository;
use Doctrine\ORM\EntityManagerInterface;
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
        int                    $gameId,
        UpdateGameDto          $dto,
        GameRepository         $gameRepository,
        Request                $request,
        EntityManagerInterface $entityManager,
    ): Response
    {
        $game = $gameRepository->find($gameId);

        if (!$game) {
            return $this->json(
                ['error' => 'Game not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

            if (empty($data)) {
                return $this->json(
                    ['error' => 'No data provided'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            if ($dto->status !== null) {
                if (is_string($data['status'])) {
                    $game->setStatus(GameStatus::from($data['status']));
                } elseif ($data['status'] instanceof GameStatus) {
                    $game->setStatus($dto->status);
                }
            }

            if ($dto->round !== null) {
                $game->setRound($dto->round);
            }

            if ($dto->startscore !== null) {
                $game->setStartScore($dto->startscore);
            }

            if ($dto->doubleout !== null) {
                $game->setDoubleOut($dto->doubleout);
            }

            if ($dto->tripleout !== null) {
                $game->setTripleOut($dto->tripleout);
            }

            $entityManager->flush();

            return $this->json($game, context: ['groups' => 'game:read']);
        } catch (\JsonException $e) {
            return $this->json(
                ['error' => 'Invalid JSON'],
                Response::HTTP_BAD_REQUEST
            );
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
}
