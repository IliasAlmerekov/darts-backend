<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\TabletGameDto;
use App\Dto\TabletGameResponseDto;
use App\Http\Attribute\ApiResponse;
use App\Repository\GameRepositoryInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin lookup of the game on the tablet.
 *
 * @psalm-suppress UnusedClass Routed by Symfony framework
 */
#[OA\Tag(name: 'Game')]
final class TabletGameController extends AbstractController
{
    /**
     * Returns the tablet game and the last finished game.
     *
     * @param GameRepositoryInterface $gameRepository
     *
     * @return TabletGameResponseDto
     */
    #[OA\Response(
        response: 200,
        description: 'Das Tablet-Spiel (`lobby` oder `started`) und das zuletzt beendete Spiel nach `finishedAt`; `game` und `lastFinishedGameId` sind `null`, wenn es keins gibt.',
        content: new OA\JsonContent(
            ref: new Model(type: TabletGameResponseDto::class),
            examples: [
                new OA\Examples(example: 'started', summary: 'Ein Spiel läuft', value: ['game' => ['id' => 57, 'status' => 'started'], 'lastFinishedGameId' => 56]),
                new OA\Examples(example: 'none', summary: 'Kein Tablet-Spiel', value: ['game' => null, 'lastFinishedGameId' => 56]),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Nicht angemeldet.')]
    #[OA\Response(response: 403, description: 'Angemeldet ohne `ROLE_ADMIN` (z. B. ein Spieler).')]
    #[ApiResponse]
    #[Route('/api/tablet/game', name: 'app_tablet_game', methods: ['GET'], format: 'json')]
    public function tabletGame(GameRepositoryInterface $gameRepository): TabletGameResponseDto
    {
        ['tabletGame' => $tabletGame, 'lastFinishedGameId' => $lastFinishedGameId] = $gameRepository->findTabletGameAndLastFinishedGameId();

        return new TabletGameResponseDto(
            game: null !== $tabletGame ? new TabletGameDto($tabletGame['gameId'], $tabletGame['status']->value) : null,
            lastFinishedGameId: $lastFinishedGameId,
        );
    }
}
