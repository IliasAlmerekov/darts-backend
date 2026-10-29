<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\GameSettingsRequest;
use App\Dto\GameSettingsResponseDto;
use App\Entity\Game;
use App\Exception\Game\GameIdMissingException;
use App\Http\Attribute\ApiResponse;
use App\Service\Game\GameSettingsServiceInterface;
use App\Service\Security\GameAccessServiceInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bridge\Doctrine\Attribute\MapEntity as AttributeMapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Settings endpoints for games: read and update settings.
 *
 * @psalm-suppress UnusedClass Routed by Symfony framework
 */
#[OA\Tag(name: 'Game Lifecycle')]
final class GameSettingsController extends AbstractController
{
    /**
     * Updates settings for an existing game.
     *
     * @param Game                         $game
     * @param GameSettingsServiceInterface $gameSettingsService
     * @param GameSettingsRequest          $dto
     *
     * @return GameSettingsResponseDto
     */
    #[OA\Patch(
        summary: 'Kompakte Spieleinstellungen aktualisieren',
        description: 'Bevorzugter Settings-Flow nach der Raumerstellung. Aktualisiert nur Einstellungen und gibt `GameSettingsResponseDto` statt des vollständigen Spielzustands zurück.'
    )]
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: GameSettingsRequest::class)))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Spieleinstellungen wurden aktualisiert.',
        content: new OA\JsonContent(ref: new Model(type: GameSettingsResponseDto::class))
    )]
    #[ApiResponse]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Der Request-Body ist kein gültiges JSON (`REQUEST_INVALID_JSON_BODY`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Der Request-Body ist ungültig und besteht die Validierung nicht, oder das Spiel hätte danach `doubleOut` und `tripleOut` zugleich (`GAME_CONFLICTING_OUT_RULES`). Das Spiel bleibt unverändert.', content: new OA\JsonContent(anyOf: [new OA\Schema(ref: '#/components/schemas/RequestValidationFailed'), new OA\Schema(ref: '#/components/schemas/ApiError')]))]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: Nur ROLE_ADMIN (das Tablet) darf diese Route nutzen (`ACCESS_DENIED`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'Das Spiel ist gestartet und der Request sendet `startScore` (`GAME_START_SCORE_CHANGE_NOT_ALLOWED`), oder das Spiel hat schon einen Wurf und der Request sendet `outMode`, `doubleOut` oder `tripleOut`, auch mit dem aktuellen Wert (`GAME_OUT_RULE_CHANGE_NOT_ALLOWED`). Nachdem Undo jeden Wurf entfernt hat, ist die Out-Regel wieder änderbar.')]
    #[Route('/api/game/{gameId}/settings', name: 'app_game_settings', methods: ['PATCH'], format: 'json')]
    public function updateSettings(#[AttributeMapEntity(id: 'gameId')] Game $game, GameSettingsServiceInterface $gameSettingsService, #[MapRequestPayload] GameSettingsRequest $dto): GameSettingsResponseDto
    {
        $gameSettingsService->updateSettings($game, $dto);

        return $this->createGameSettingsResponseDto($game);
    }

    /**
     * Returns lightweight game settings payload.
     *
     * @param Game                       $game
     * @param GameAccessServiceInterface $gameAccessService
     *
     * @return GameSettingsResponseDto
     */
    #[OA\Get(
        summary: 'Kompakte Spieleinstellungen lesen',
        description: 'Bevorzugter Read-Flow für Spieleinstellungen. Liefert nur settings-bezogene Daten und vermeidet den vollständigen Spielzustand.'
    )]
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Leichtgewichtige Spieleinstellungen.',
        content: new OA\JsonContent(ref: new Model(type: GameSettingsResponseDto::class))
    )]
    #[ApiResponse]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: Nur ROLE_ADMIN (das Tablet) darf diese Route nutzen (`ACCESS_DENIED`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[Route('/api/game/{gameId}/settings', name: 'app_game_settings_read', methods: ['GET'], format: 'json')]
    public function getSettings(#[AttributeMapEntity(id: 'gameId')] Game $game, GameAccessServiceInterface $gameAccessService): GameSettingsResponseDto
    {
        $gameAccessService->assertAdmin();

        return $this->createGameSettingsResponseDto($game);
    }

    /**
     * Builds the compact settings payload for settings-related endpoints.
     *
     * @param Game $game
     *
     * @return GameSettingsResponseDto
     */
    private function createGameSettingsResponseDto(Game $game): GameSettingsResponseDto
    {
        $gameId = $game->getGameId();
        if (null === $gameId) {
            throw new GameIdMissingException();
        }

        return new GameSettingsResponseDto(
            gameId: $gameId,
            startScore: $game->getStartScore(),
            doubleOut: $game->isDoubleOut(),
            tripleOut: $game->isTripleOut(),
            status: $game->getStatus()->value,
        );
    }
}
