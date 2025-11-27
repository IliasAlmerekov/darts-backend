<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\StartGameRequest;
use App\Dto\ThrowRequest;
use App\Service\GameFinishService;
use App\Service\GameStartService;
use App\Service\GameThrowService;
use InvalidArgumentException;
use App\Repository\GameRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @throws InvalidArgumentException
 * Controller to manage game actions such as starting a game and recording throws.
 */
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

    /**
     * @throws InvalidArgumentException
     * This function records a throw for a player in a game.
     */
    #[Route('/api/game/{gameId}/throw', name: 'app_game_throw', methods: ['POST'])]
    public function throw(
        int $gameId,
        #[MapRequestPayload] ThrowRequest $dto,
        GameRepository $gameRepository,
        GameThrowService $gameThrowService,
    ): Response {
        $game = $gameRepository->find($gameId);

        if (!$game) {
            return $this->json(
                ['error' => 'Game not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        try {
            $gameThrowService->recordThrow($game, $dto);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($game, context: ['groups' => 'game:read']);
    }

    #[Route('/api/game/{gameId}/throw', name: 'app_game_throw_undo', methods: ['DELETE'])]
    public function undoThrow(
        int $gameId,
        GameRepository $gameRepository,
        GameThrowService $gameThrowService,
    ): Response {
        $game = $gameRepository->find($gameId);

        if (!$game) {
            return $this->json(
                ['error' => 'Game not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        $gameThrowService->undoLastThrow($game);

        return $this->json($game, context: ['groups' => 'game:read']);
    }


    #[Route('/api/game/{gameId}/finished', name: 'app_game_finished', methods: ['GET'])]
    public function finished(
        int $gameId,
        GameRepository $gameRepository,
        GameFinishService $gameFinishService,
    ): Response {
        $game = $gameRepository->find($gameId);

        if (!$game) {
            return $this->json(
                ['error' => 'Game not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        try {
            $result = $gameFinishService->finishGame(
                $game,
                null
            );
        } catch (\Throwable $e) {
            return $this->json(
                ['error' => 'An error occurred: ' . $e->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $this->json($result, context: ['groups' => 'game:read']);
    }
}
