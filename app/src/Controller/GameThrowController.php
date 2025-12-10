<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ThrowRequest;
use App\Entity\Game;
use App\Service\Game\GameServiceInterface;
use App\Service\Game\GameThrowServiceInterface;
use InvalidArgumentException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity as AttributeMapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints for recording and undoing throws.
 */
final class GameThrowController extends AbstractController
{
    #[Route('/api/game/{gameId}/throw', name: 'app_game_throw', methods: ['POST'], format: 'json')]
    /**
     * @param Game                      $game
     * @param GameThrowServiceInterface $gameThrowService
     * @param GameServiceInterface      $gameService
     * @param ThrowRequest              $dto
     *
     * @return Response
     */
    public function throw(
        #[AttributeMapEntity(id: 'gameId')] Game $game,
        GameThrowServiceInterface $gameThrowService,
        GameServiceInterface $gameService,
        #[MapRequestPayload] ThrowRequest $dto,
    ): Response {
        try {
            $gameThrowService->recordThrow($game, $dto);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $gameDto = $gameService->createGameDto($game);

        return $this->json($gameDto);
    }

    #[Route('/api/game/{gameId}/throw', name: 'app_game_throw_undo', methods: ['DELETE'], format: 'json')]
    /**
     * @param Game                      $game
     * @param GameThrowServiceInterface $gameThrowService
     * @param GameServiceInterface      $gameService
     *
     * @return Response
     */
    public function undoThrow(
        #[AttributeMapEntity(id: 'gameId')] Game $game,
        GameThrowServiceInterface $gameThrowService,
        GameServiceInterface $gameService
    ): Response {
        $gameThrowService->undoLastThrow($game);
        $gameDto = $gameService->createGameDto($game);

        return $this->json($gameDto);
    }
}
