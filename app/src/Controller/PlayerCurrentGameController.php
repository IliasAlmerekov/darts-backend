<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CurrentGameResponseDto;
use App\Entity\User;
use App\Http\Attribute\ApiResponse;
use App\Repository\GamePlayersRepositoryInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Player-facing lookup of the game a player currently belongs to.
 *
 * @psalm-suppress UnusedClass Routed by Symfony framework
 */
#[OA\Tag(name: 'Player')]
final class PlayerCurrentGameController extends AbstractController
{
    /**
     * Returns the newest Lobby or Started game the player still sits in.
     *
     * @param GamePlayersRepositoryInterface $gamePlayersRepository
     *
     * @return CurrentGameResponseDto
     */
    #[OA\Response(
        response: 200,
        description: 'Aktuelles Spiel des Spielers (`lobby` oder `started`); `gameId` und `status` sind `null`, wenn es keins gibt.',
        content: new OA\JsonContent(
            ref: new Model(type: CurrentGameResponseDto::class),
            examples: [
                new OA\Examples(example: 'lobby', summary: 'Spieler ist in einer Lobby', value: ['gameId' => 12, 'status' => 'lobby']),
                new OA\Examples(example: 'none', summary: 'Kein aktuelles Spiel', value: ['gameId' => null, 'status' => null]),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Nicht angemeldet.')]
    #[OA\Response(response: 403, description: 'Angemeldet ohne `ROLE_PLAYER` (z. B. der Admin).')]
    #[ApiResponse]
    #[Route('/api/player/current-game', name: 'app_player_current_game', methods: ['GET'], format: 'json')]
    public function currentGame(GamePlayersRepositoryInterface $gamePlayersRepository): CurrentGameResponseDto
    {
        $user = $this->getUser();
        $playerId = $user instanceof User ? $user->getId() : null;
        $current = null !== $playerId ? $gamePlayersRepository->findCurrentGameForPlayer($playerId) : null;

        return new CurrentGameResponseDto(
            gameId: $current['gameId'] ?? null,
            status: $current['status']->value ?? null,
        );
    }
}
