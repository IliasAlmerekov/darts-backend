<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\GameSummaryResponseDto;
use App\Dto\GameResponseDto;
use App\Dto\SuccessMessageDto;
use App\Dto\StartGameRequest;
use App\Entity\Game;
use App\Exception\ApiExceptionInterface;
use App\EventSubscriber\ApiViewSubscriber;
use App\Http\Attribute\ApiResponse;
use App\Service\Game\GameAbortServiceInterface;
use App\Service\Game\GameFinishServiceInterface;
use App\Service\Game\GameReopenServiceInterface;
use App\Service\Game\GameStateReaderInterface;
use App\Service\Game\GameStartServiceInterface;
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
 * Lifecycle endpoints for games: start, finish, reopen, abort, state.
 */
#[OA\Tag(name: 'Game Lifecycle')]
final class GameLifecycleController extends AbstractController
{
    /**
     * Starts a game with provided settings.
     *
     * @param Game                      $game
     * @param GameStartServiceInterface $gameStartService
     * @param GameStateReaderInterface  $gameStateReader
     * @param Request                   $request
     * @param StartGameRequest          $dto
     *
     * @return GameResponseDto
     *
     * @throws ApiExceptionInterface
     */
    #[OA\Parameter(name: 'gameId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: StartGameRequest::class)))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Aktualisierter Spielzustand nach dem Start.',
        content: new OA\JsonContent(ref: new Model(type: GameResponseDto::class))
    )]
    #[ApiResponse]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Der Request-Body ist kein gültiges JSON (`REQUEST_INVALID_JSON_BODY`), die Lobby hat weniger als 2 oder mehr als ' . Game::MAX_PARTICIPANTS . ' Spieler (`GAME_INVALID_PLAYER_COUNT`), `playerPositions` nennt nicht so viele Spieler, wie am Spiel teilnehmen (`GAME_PLAYER_POSITIONS_COUNT_MISMATCH`), oder `playerPositions` enthält eine Spieler-ID doppelt oder eine ID, die nicht zum Spiel gehört (`GAME_INVALID_PLAYER_ORDER`). Das Spiel bleibt unverändert.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Der Request-Body ist ungültig und besteht die Validierung nicht, oder das Spiel hätte danach `doubleOut` und `tripleOut` zugleich (`GAME_CONFLICTING_OUT_RULES`). Das Spiel bleibt unverändert.', content: new OA\JsonContent(anyOf: [new OA\Schema(ref: '#/components/schemas/RequestValidationFailed'), new OA\Schema(ref: '#/components/schemas/ApiError')]))]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: Nur ROLE_ADMIN (das Tablet) darf diese Route nutzen (`ACCESS_DENIED`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[Route('/api/game/{gameId}/start', name: 'app_game_start', methods: ['POST'], format: 'json')]
    public function start(#[AttributeMapEntity(id: 'gameId')] Game $game, GameStartServiceInterface $gameStartService, GameStateReaderInterface $gameStateReader, Request $request, #[MapRequestPayload] StartGameRequest $dto): GameResponseDto
    {
        $started = $gameStartService->start($game, $dto);
        $request->attributes->set(
            ApiViewSubscriber::RESPONSE_HEADERS_REQUEST_ATTRIBUTE,
            $this->createStateVersionHeaders($gameStateReader->stateVersion($started)),
        );

        return $gameStateReader->createGameDto($started);
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
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: Nur ROLE_ADMIN (das Tablet) darf diese Route nutzen (`ACCESS_DENIED`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
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
     * @param Game                       $game
     * @param GameReopenServiceInterface $gameReopenService
     * @param GameStateReaderInterface   $gameStateReader
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
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: Nur ROLE_ADMIN (das Tablet) darf diese Route nutzen (`ACCESS_DENIED`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'Das Spiel ist weder beendet noch gestartet (`GAME_REOPEN_NOT_ALLOWED`), oder es ist beendet, aber nicht das neueste Spiel (`GAME_NOT_NEWEST`).')]
    #[Route('/api/game/{gameId}/reopen', name: 'app_game_reopen', methods: ['PATCH'], format: 'json')]
    public function reopen(#[AttributeMapEntity(id: 'gameId')] Game $game, GameReopenServiceInterface $gameReopenService, GameStateReaderInterface $gameStateReader): mixed
    {
        return $gameStateReader->createGameDto($gameReopenService->reopen($game));
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
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: Nur ROLE_ADMIN (das Tablet) darf diese Route nutzen (`ACCESS_DENIED`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'Das Spiel ist nicht beendet (`GAME_NOT_FINISHED`).')]
    #[Route('/api/game/{gameId}/finished', name: 'app_game_finished', methods: ['GET'], format: 'json')]
    public function finished(#[AttributeMapEntity(id: 'gameId')] Game $game, GameFinishServiceInterface $gameFinishService): GameSummaryResponseDto
    {
        return $gameFinishService->getGameSummary($game);
    }

    /**
     * Returns the current game state.
     *
     * @param Game                       $game
     * @param GameAccessServiceInterface $gameAccessService
     * @param GameStateReaderInterface   $gameStateReader
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
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: Nur ROLE_ADMIN (das Tablet) darf diese Route nutzen (`ACCESS_DENIED`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[Route('/api/game/{gameId}', name: 'app_game_state', methods: ['GET'], format: 'json')]
    public function getGameState(#[AttributeMapEntity(id: 'gameId')] Game $game, GameAccessServiceInterface $gameAccessService, GameStateReaderInterface $gameStateReader, Request $request, #[MapQueryParameter] ?string $since = null): Response
    {
        $gameAccessService->assertAdmin();
        $stateVersion = $gameStateReader->stateVersion($game);
        $headers = $this->createStateVersionHeaders($stateVersion);
        $isNotModified = $this->isMatchingVersion($request->headers->get('If-None-Match'), $stateVersion) || $since === $stateVersion;
        if ($isNotModified) {
            return new Response('', Response::HTTP_NOT_MODIFIED, $headers);
        }

        $gameDto = $gameStateReader->createGameDto($game);
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
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: Nur ROLE_ADMIN (das Tablet) darf diese Route nutzen (`ACCESS_DENIED`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'Das Spiel ist in der Lobby oder beendet (`GAME_ABORT_NOT_ALLOWED`). Ein bereits abgebrochenes Spiel bleibt unverändert (200).')]
    #[Route('/api/game/{gameId}/abort', name: 'app_game_abort', methods: ['PATCH'], format: 'json')]
    public function abortGame(#[AttributeMapEntity(id: 'gameId')] Game $game, GameAbortServiceInterface $gameAbortService): SuccessMessageDto
    {
        $gameAbortService->abortGame($game);

        return new SuccessMessageDto('Game aborted successfully');
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
