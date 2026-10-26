<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\GameSummaryResponseDto;
use App\Dto\GameSettingsResponseDto;
use App\Dto\GameSettingsRequest;
use App\Dto\GameResponseDto;
use App\Dto\SuccessMessageDto;
use App\Dto\StartGameRequest;
use App\Entity\Game;
use App\Exception\ApiExceptionInterface;
use App\Exception\Game\GameIdMissingException;
use App\Exception\Game\GameNotFoundException;
use App\EventSubscriber\ApiViewSubscriber;
use App\Http\Attribute\ApiResponse;
use App\Repository\GameRepositoryInterface;
use App\Service\Game\GameAbortServiceInterface;
use App\Service\Game\GameFinishServiceInterface;
use App\Service\Game\GameReopenServiceInterface;
use App\Service\Game\GameRoomServiceInterface;
use App\Service\Game\GameServiceInterface;
use App\Service\Game\GameSettingsServiceInterface;
use App\Service\Game\GameStartServiceInterface;
use App\Service\Game\RematchStartServiceInterface;
use App\Service\Persistence\RetryingTransactionInterface;
use App\Service\Security\GameAccessServiceInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bridge\Doctrine\Attribute\MapEntity as AttributeMapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lifecycle endpoints for games: start, settings, finish, state.
 */
#[OA\Tag(name: 'Game Lifecycle')]
final class GameLifecycleController extends AbstractController
{
    /**
     * Starts a game with provided settings.
     *
     * @param Game                         $game
     * @param GameStartServiceInterface    $gameStartService
     * @param GameServiceInterface         $gameService
     * @param GameRepositoryInterface      $gameRepository
     * @param RetryingTransactionInterface $transaction
     * @param Request                      $request
     * @param StartGameRequest             $dto
     *
     * @return Game
     *
     * @throws ApiExceptionInterface
     */
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: StartGameRequest::class)))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Aktualisierter Spielzustand nach dem Start.',
        content: new OA\JsonContent(ref: new Model(type: Game::class, groups: ['game:read']))
    )]
    #[ApiResponse(groups: ['game:read'])]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Ein Setting wurde in camelCase und in Kleinschreibung mit unterschiedlichen Werten gesendet (`REQUEST_CONFLICTING_SETTING_KEYS`).')]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Der Request-Body ist ungültig und besteht die Validierung nicht, oder das Spiel hätte danach `doubleOut` und `tripleOut` zugleich (`GAME_CONFLICTING_OUT_RULES`). Das Spiel bleibt unverändert.')]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: `/api` ist per Firewall nur für ROLE_ADMIN offen, und der Code prüft zusätzlich die Spielteilnahme.')]
    #[Route('/api/game/{gameId}/start', name: 'app_game_start', methods: ['POST'], format: 'json')]
    public function start(#[AttributeMapEntity(id: 'gameId')] Game $game, GameStartServiceInterface $gameStartService, GameServiceInterface $gameService, GameRepositoryInterface $gameRepository, RetryingTransactionInterface $transaction, Request $request, #[MapRequestPayload] StartGameRequest $dto): Game
    {
        $gameId = $game->getGameId() ?? throw new GameNotFoundException();
        $started = $transaction->run(static function () use ($gameRepository, $gameStartService, $gameId, $dto): Game {
            // A retry follows resetManager(), which detaches the game the route resolved.
            $game = $gameRepository->findOneByGameId($gameId) ?? throw new GameNotFoundException();
            $gameStartService->start($game, $dto);

            return $game;
        });
        $request->attributes->set(
            ApiViewSubscriber::RESPONSE_HEADERS_REQUEST_ATTRIBUTE,
            $this->createStateVersionHeaders($gameService->buildStateVersion($started)),
        );

        return $started;
    }

    /**
     * Starts the open lobby, or a new one, as a rematch of a finished game.
     *
        * @param int                          $gameId
     * @param RematchStartServiceInterface $rematchStartService
     * @param RetryingTransactionInterface $transaction
        * @param StartGameRequest             $dto
     *
     * @return Game
     *
     * @throws ApiExceptionInterface
     */
    #[OA\Post(
        summary: 'Rematch in der offenen Lobby oder in einer neuen Lobby direkt starten',
        description: 'Wie `POST /api/room/{id}/rematch`, danach wird das Spiel gestartet. Es ist höchstens ein Spiel in der Lobby. Ist ein Spiel in der Lobby, startet dieses Spiel mit seinen Spielern und den fehlenden Spielern des alten Spiels; sonst wird eine neue Lobby angelegt und gestartet.'
    )]
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: StartGameRequest::class)))]
    #[OA\Response(
        response: Response::HTTP_CREATED,
        description: 'Die offene Lobby oder eine neue Lobby wurde mit den Spielern befüllt und gestartet.',
        content: new OA\JsonContent(ref: new Model(type: Game::class, groups: ['game:read']))
    )]
    #[ApiResponse(status: Response::HTTP_CREATED, groups: ['game:read'])]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Ein Setting wurde in camelCase und in Kleinschreibung mit unterschiedlichen Werten gesendet (`REQUEST_CONFLICTING_SETTING_KEYS`).')]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Der Request-Body ist ungültig und besteht die Validierung nicht, oder das Spiel hätte danach `doubleOut` und `tripleOut` zugleich (`GAME_CONFLICTING_OUT_RULES`). Das Spiel bleibt unverändert.')]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: `/api` ist per Firewall nur für ROLE_ADMIN offen, und der Code prüft zusätzlich die Spielteilnahme.')]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'Das alte Spiel ist nicht das neueste Spiel, oder es ist weder beendet noch abgebrochen (`GAME_NOT_NEWEST`). Eine offene Lobby ist immer das neueste Spiel.')]
    #[Route('/api/game/{gameId}/rematch/start', name: 'app_game_rematch_start', methods: ['POST'], format: 'json')]
    public function createAndStartRematch(int $gameId, RematchStartServiceInterface $rematchStartService, RetryingTransactionInterface $transaction, #[MapRequestPayload] StartGameRequest $dto): Game
    {
        return $transaction->run(static fn (): Game => $rematchStartService->createAndStart($gameId, $dto));
    }

    /**
     * Creates a game and applies settings.
      *
      * @deprecated Use POST /api/room/create followed by PATCH/GET /api/game/{gameId}/settings for the compact settings flow.
     *
     * @param GameRoomServiceInterface     $gameRoomService
     * @param GameSettingsServiceInterface $gameSettingsService
     * @param GameServiceInterface         $gameService
     * @param RetryingTransactionInterface $transaction
     * @param GameSettingsRequest          $dto
     *
     * @return mixed
     */
    #[OA\Post(
        summary: 'Legacy-Endpoint für Spielanlage mit vollständigem Spielzustand',
        description: 'Deprecated. Ist ein Spiel in der Lobby, liefert der Endpoint dieses Spiel und überschreibt seine Einstellungen; sonst legt er eine neue Lobby an. Verwenden Sie stattdessen `POST /api/room/create`, danach `PATCH /api/game/{gameId}/settings` und `GET /api/game/{gameId}/settings` für den kompakten Settings-Flow.',
        deprecated: true
    )]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: GameSettingsRequest::class)))]
    #[OA\Response(
        response: Response::HTTP_CREATED,
        description: 'Deprecated: liefert die offene Lobby oder eine neue Lobby mit vollständigem Spielzustand zurück.',
        content: new OA\JsonContent(ref: new Model(type: GameResponseDto::class))
    )]
    #[ApiResponse(status: Response::HTTP_CREATED)]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Der Request-Body ist ungültig und besteht die Validierung nicht, oder das Spiel hätte danach `doubleOut` und `tripleOut` zugleich (`GAME_CONFLICTING_OUT_RULES`). Das Spiel bleibt unverändert.')]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: `/api` ist per Firewall nur für ROLE_ADMIN offen, und der Code prüft zusätzlich die Spielteilnahme.')]
    #[Route('/api/game/settings', name: 'app_game_settings_create', methods: ['POST'], format: 'json')]
    public function createSettings(GameRoomServiceInterface $gameRoomService, GameSettingsServiceInterface $gameSettingsService, GameServiceInterface $gameService, RetryingTransactionInterface $transaction, #[MapRequestPayload] GameSettingsRequest $dto): mixed
    {
        return $transaction->run(function () use ($gameRoomService, $gameSettingsService, $gameService, $dto): mixed {
            $game = $gameRoomService->openRoom();

            $gameSettingsService->updateSettings($game, $dto);

            $gameDto = $gameService->createGameDto($game);

            return $gameDto;
        });
    }

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
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Der Request-Body ist ungültig und besteht die Validierung nicht, oder das Spiel hätte danach `doubleOut` und `tripleOut` zugleich (`GAME_CONFLICTING_OUT_RULES`). Das Spiel bleibt unverändert.')]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: `/api` ist per Firewall nur für ROLE_ADMIN offen, und der Code prüft zusätzlich die Spielteilnahme.')]
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
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: `/api` ist per Firewall nur für ROLE_ADMIN offen, und der Code prüft zusätzlich die Spielteilnahme.')]
    #[Route('/api/game/{gameId}/settings', name: 'app_game_settings_read', methods: ['GET'], format: 'json')]
    public function getSettings(#[AttributeMapEntity(id: 'gameId')] Game $game, GameAccessServiceInterface $gameAccessService): GameSettingsResponseDto
    {
        $gameAccessService->assertPlayerInGameOrAdmin($game);

        return $this->createGameSettingsResponseDto($game);
    }

    /**
     * Finishes a game and returns a normalized summary.
     *
     * @param Game                       $game
     * @param GameFinishServiceInterface $gameFinishService
     *
     * @return GameSummaryResponseDto
     */
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Spiel beenden und normalisierte Summary zurückgeben.',
        content: new OA\JsonContent(ref: new Model(type: GameSummaryResponseDto::class))
    )]
    #[ApiResponse]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: `/api` ist per Firewall nur für ROLE_ADMIN offen, und der Code prüft zusätzlich die Spielteilnahme.')]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'Das Spiel ist in der Lobby oder abgebrochen (`GAME_FINISH_NOT_ALLOWED`). Ein bereits beendetes Spiel bleibt unverändert (200).')]
    #[Route('/api/game/{gameId}/finish', name: 'app_game_finish', methods: ['POST'], format: 'json')]
    public function finish(#[AttributeMapEntity(id: 'gameId')] Game $game, GameFinishServiceInterface $gameFinishService): GameSummaryResponseDto
    {
        $result = $gameFinishService->finishGame($game);

        return $result;
    }

    /**
     * Reopens a finished game and returns current state.
     *
     * @param Game                         $game
     * @param GameReopenServiceInterface   $gameReopenService
     * @param GameServiceInterface         $gameService
     * @param GameRepositoryInterface      $gameRepository
     * @param RetryingTransactionInterface $transaction
     *
     * @return mixed
     */
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Spiel wurde wieder geöffnet und befindet sich erneut im started-Status. Ein bereits gestartetes Spiel bleibt unverändert.',
        content: new OA\JsonContent(ref: new Model(type: GameResponseDto::class))
    )]
    #[ApiResponse]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: `/api` ist per Firewall nur für ROLE_ADMIN offen, und der Code prüft zusätzlich die Spielteilnahme.')]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'Das Spiel ist weder beendet noch gestartet (`GAME_REOPEN_NOT_ALLOWED`), oder es ist beendet, aber nicht das neueste Spiel (`GAME_NOT_NEWEST`).')]
    #[Route('/api/game/{gameId}/reopen', name: 'app_game_reopen', methods: ['PATCH'], format: 'json')]
    public function reopen(#[AttributeMapEntity(id: 'gameId')] Game $game, GameReopenServiceInterface $gameReopenService, GameServiceInterface $gameService, GameRepositoryInterface $gameRepository, RetryingTransactionInterface $transaction): mixed
    {
        $gameId = $game->getGameId() ?? throw new GameNotFoundException();
        $reopened = $transaction->run(static function () use ($gameRepository, $gameReopenService, $gameId): Game {
            // A retry follows resetManager(), which detaches the game the route resolved.
            $game = $gameRepository->findOneByGameId($gameId) ?? throw new GameNotFoundException();
            $gameReopenService->reopen($game);

            return $game;
        });

        return $gameService->createGameDto($reopened);
    }

    /**
     * Returns finished game summary without mutating game state.
     *
     * @param Game                       $game
     * @param GameFinishServiceInterface $gameFinishService
     *
     * @return GameSummaryResponseDto
     */
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Normalisierte Summary des Spiels (read-only).',
        content: new OA\JsonContent(ref: new Model(type: GameSummaryResponseDto::class))
    )]
    #[ApiResponse]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: `/api` ist per Firewall nur für ROLE_ADMIN offen, und der Code prüft zusätzlich die Spielteilnahme.')]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'Das Spiel ist nicht beendet (`GAME_NOT_FINISHED`).')]
    #[Route('/api/game/{gameId}/finished', name: 'app_game_finished', methods: ['GET'], format: 'json')]
    #[Route('/api/games/{gameId}/finished', name: 'app_games_finished', methods: ['GET'], format: 'json', requirements: ['gameId' => '\d+'])]
    public function finished(#[AttributeMapEntity(id: 'gameId')] Game $game, GameFinishServiceInterface $gameFinishService): GameSummaryResponseDto
    {
        return $gameFinishService->getGameSummary($game);
    }

    /**
     * Returns the current game state.
     *
     * @param Game                       $game
     * @param GameAccessServiceInterface $gameAccessService
     * @param GameServiceInterface       $gameService
     * @param Request                    $request
     * @param string|null                $since
     *
     * @return Response
     */
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\Parameter(
        name: 'since',
        description: 'Optional stateVersion from the previous response. If unchanged, endpoint returns 304.',
        in: 'query',
        required: false,
        schema: new OA\Schema(type: 'string', example: 'c9a4f0d9...')
    )]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Aktueller Spielzustand.',
        content: new OA\JsonContent(ref: new Model(type: GameResponseDto::class))
    )]
    #[OA\Response(response: Response::HTTP_NOT_MODIFIED, description: 'Spielzustand hat sich seit der letzten Version nicht geändert.')]
    #[ApiResponse]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: `/api` ist per Firewall nur für ROLE_ADMIN offen, und der Code prüft zusätzlich die Spielteilnahme.')]
    #[Route('/api/game/{gameId}', name: 'app_game_state', methods: ['GET'], format: 'json')]
    public function getGameState(#[AttributeMapEntity(id: 'gameId')] Game $game, GameAccessServiceInterface $gameAccessService, GameServiceInterface $gameService, Request $request, #[MapQueryParameter] ?string $since = null): Response
    {
        $gameAccessService->assertPlayerInGameOrAdmin($game);
        $stateVersion = $gameService->buildStateVersion($game);
        $headers = $this->createStateVersionHeaders($stateVersion);
        $isNotModified = $this->isMatchingVersion($request->headers->get('If-None-Match'), $stateVersion) || $since === $stateVersion;
        if ($isNotModified) {
            return new Response('', Response::HTTP_NOT_MODIFIED, $headers);
        }

        $gameDto = $gameService->createGameDto($game);
        $response = $this->json($gameDto);
        $response->headers->add($headers);

        return $response;
    }

    /**
     * Aborts a game.
     *
     * @param Game                      $game
     * @param GameAbortServiceInterface $gameAbortService
     *
     * @return SuccessMessageDto
     */
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Spiel wurde abgebrochen.',
        content: new OA\JsonContent(ref: new Model(type: SuccessMessageDto::class))
    )]
    #[ApiResponse]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: `/api` ist per Firewall nur für ROLE_ADMIN offen, und der Code prüft zusätzlich die Spielteilnahme.')]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'Das Spiel ist in der Lobby oder beendet (`GAME_ABORT_NOT_ALLOWED`). Ein bereits abgebrochenes Spiel bleibt unverändert (200).')]
    #[Route('/api/game/{gameId}/abort', name: 'app_game_abort', methods: ['PATCH'], format: 'json')]
    public function abortGame(#[AttributeMapEntity(id: 'gameId')] Game $game, GameAbortServiceInterface $gameAbortService): SuccessMessageDto
    {
        $gameAbortService->abortGame($game);

        return new SuccessMessageDto('Game aborted successfully');
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

    /**
     * @param string $stateVersion
     *
     * @return array<string, string>
     */
    private function createStateVersionHeaders(string $stateVersion): array
    {
        return [
            'ETag' => '"'.$stateVersion.'"',
            'Cache-Control' => 'private, no-cache',
            'X-Game-State-Version' => $stateVersion,
        ];
    }

    /**
     * @param string|null $ifNoneMatch
     * @param string      $stateVersion
     *
     * @return bool
     */
    private function isMatchingVersion(?string $ifNoneMatch, string $stateVersion): bool
    {
        if (null === $ifNoneMatch || '' === trim($ifNoneMatch)) {
            return false;
        }

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $token = trim($candidate);
            if ('*' === $token) {
                return true;
            }

            if (str_starts_with($token, 'W/')) {
                $token = substr($token, 2);
            }

            $token = trim($token, "\"' \t\n\r\0\x0B");
            if ($stateVersion === $token) {
                return true;
            }
        }

        return false;
    }
}
