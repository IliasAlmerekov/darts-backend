<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CurrentGameResponseDto;
use App\Dto\GameSeatResponseDto;
use App\Entity\User;
use App\Exception\Game\GameNotFoundException;
use App\Http\Attribute\ApiResponse;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\GameRepositoryInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Player-facing lookups of the games a player belongs to.
 *
 * @psalm-suppress UnusedClass Routed by Symfony framework
 */
#[OA\Tag(name: 'Player')]
final class PlayerCurrentGameController extends AbstractController
{
    /**
     * Returns the Lobby or Started game the player joined most recently and still sits in.
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
    #[OA\Response(response: 403, description: 'Angemeldet ohne `ROLE_PLAYER` (z. B. der Admin) (`ACCESS_DENIED`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
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

    /**
     * Returns a game's status and whether the player still holds a seat in it, so the Waiting
     * room can tell a removal from a finished or aborted game. Nothing about other players.
     *
     * @param int                            $gameId
     * @param GameRepositoryInterface        $gameRepository
     * @param GamePlayersRepositoryInterface $gamePlayersRepository
     *
     * @return GameSeatResponseDto
     */
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 12))]
    #[OA\Response(
        response: 200,
        description: 'Status des Spiels und ob der Spieler darin noch einen Platz hat.',
        content: new OA\JsonContent(
            ref: new Model(type: GameSeatResponseDto::class),
            examples: [
                new OA\Examples(example: 'seated', summary: 'Spieler sitzt in der Lobby', value: ['gameId' => 12, 'status' => 'lobby', 'seated' => true]),
                new OA\Examples(example: 'removed', summary: 'Admin hat den Spieler aus der Lobby entfernt', value: ['gameId' => 12, 'status' => 'lobby', 'seated' => false]),
                new OA\Examples(example: 'finished', summary: 'Spiel ist beendet', value: ['gameId' => 12, 'status' => 'finished', 'seated' => true]),
                new OA\Examples(example: 'aborted', summary: 'Spiel wurde abgebrochen', value: ['gameId' => 12, 'status' => 'aborted', 'seated' => true]),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Nicht angemeldet.')]
    #[OA\Response(response: 403, description: 'Angemeldet ohne `ROLE_PLAYER` (z. B. der Admin) (`ACCESS_DENIED`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[OA\Response(response: 404, description: 'Spiel nicht gefunden (`GAME_NOT_FOUND`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[ApiResponse]
    #[Route('/api/player/games/{gameId}/seat', name: 'app_player_game_seat', requirements: ['gameId' => '\d+'], methods: ['GET'], format: 'json')]
    public function seat(int $gameId, GameRepositoryInterface $gameRepository, GamePlayersRepositoryInterface $gamePlayersRepository): GameSeatResponseDto
    {
        $status = $gameRepository->findStatus($gameId) ?? throw new GameNotFoundException();
        $user = $this->getUser();
        $playerId = $user instanceof User ? $user->getId() : null;

        return new GameSeatResponseDto(
            gameId: $gameId,
            status: $status->value,
            seated: null !== $playerId && $gamePlayersRepository->isPlayerInGame($gameId, $playerId),
        );
    }
}
