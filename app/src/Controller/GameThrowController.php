<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ThrowRequest;
use App\Entity\Game;
use App\Http\Attribute\ApiResponse;
use App\Service\Game\GameServiceInterface;
use App\Service\Game\GameThrowServiceInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity as AttributeMapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints for recording and undoing throws.
 */
final class GameThrowController extends AbstractController
{
    /**
     * @param Game                      $game
     * @param GameThrowServiceInterface $gameThrowService
     * @param GameServiceInterface      $gameService
     * @param ThrowRequest              $dto
     *
     * @return mixed
     */
    #[ApiResponse]
    #[Route('/api/game/{gameId}/throw', name: 'app_game_throw', methods: ['POST'], format: 'json')]
    public function throw(#[AttributeMapEntity(id: 'gameId')] Game $game, GameThrowServiceInterface $gameThrowService, GameServiceInterface $gameService, #[MapRequestPayload] ThrowRequest $dto): mixed
    {
        $gameThrowService->recordThrow($game, $dto);

        $gameDto = $gameService->createGameDto($game);

        return $gameDto;
    }

    /**
     * @param Game                      $game
     * @param GameThrowServiceInterface $gameThrowService
     * @param GameServiceInterface      $gameService
     *
     * @return mixed
     */
    #[ApiResponse]
    #[Route('/api/game/{gameId}/throw', name: 'app_game_throw_undo', methods: ['DELETE'], format: 'json')]
    public function undoThrow(#[AttributeMapEntity(id: 'gameId')] Game $game, GameThrowServiceInterface $gameThrowService, GameServiceInterface $gameService): mixed
    {
        $gameThrowService->undoLastThrow($game);
        $gameDto = $gameService->createGameDto($game);

        return $gameDto;
    }
}
