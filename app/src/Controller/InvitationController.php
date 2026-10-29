<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Game;
use App\Entity\Invitation;
use App\Exception\Game\GameNotFoundException;
use App\Exception\Health\DatabaseUnavailableException;
use App\Http\Attribute\ApiResponse;
use App\Security\InvitationSession;
use App\Service\Invitation\InvitationServiceInterface;
use Doctrine\DBAL\Connection;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * This class handles invitation-related actions such as creating invitations and
 * joining via invitation links.
 * Also get as JSON responses for API requests.
 */
#[OA\Tag(name: 'Invitations')]
final class InvitationController extends AbstractController
{
    private string $frontendUrl;

    /**
     * @param string          $frontendUrl
     * @param LoggerInterface $logger
     */
    public function __construct(
        string $frontendUrl,
        private readonly LoggerInterface $logger,
    ) {
        $this->frontendUrl = rtrim($frontendUrl, '/');
    }

    /**
     * Lightweight readiness endpoint for proxy health checks.
     *
     * @return JsonResponse
     */
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Backend is reachable and ready to accept API traffic.',
        content: new OA\JsonContent(
            type: 'object',
            required: ['success', 'status'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'status', type: 'string', example: 'ok'),
            ]
        )
    )]
    #[Security(name: null)]
    #[Route('/api/health', name: 'api_health', methods: ['GET'], format: 'json')]
    public function health(): JsonResponse
    {
        return $this->json([
            'success' => true,
            'status' => 'ok',
        ]);
    }

    /**
     * Readiness endpoint: answers 200 only while the database answers a trivial query.
     *
     * @param Connection $connection
     *
     * @return JsonResponse
     *
     * @throws DatabaseUnavailableException
     */
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'The database answers; the backend can serve API traffic.',
        content: new OA\JsonContent(
            type: 'object',
            required: ['success', 'status'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'status', type: 'string', example: 'ready'),
            ]
        )
    )]
    #[OA\Response(response: Response::HTTP_SERVICE_UNAVAILABLE, description: 'Die Datenbankprüfung ist fehlgeschlagen (`DATABASE_UNAVAILABLE`), ohne Fehlerdetails.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[Security(name: null)]
    #[Route('/api/ready', name: 'api_ready', methods: ['GET'], format: 'json')]
    public function ready(Connection $connection): JsonResponse
    {
        try {
            $connection->fetchOne('SELECT 1');
        } catch (Throwable $exception) {
            // Only the log gets the cause: its text can carry the DSN or host.
            $this->logger->error('Readiness check failed: database unavailable.', ['exception' => $exception]);

            throw new DatabaseUnavailableException();
        }

        return $this->json(['success' => true, 'status' => 'ready']);
    }

    /**
     * Creates or returns an invitation for the given game.
     *
     * @param Game                       $game
     * @param InvitationServiceInterface $invitationService
     *
     * @return array<array-key, mixed>
     */
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 123))]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Einladungslink wurde erstellt oder existierte bereits.',
        content: new OA\JsonContent(
            type: 'object',
            required: ['success'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'gameId', type: 'integer', nullable: true, example: 123),
                new OA\Property(property: 'invitationLink', type: 'string', nullable: true, example: '/api/invite/join/xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'),
                new OA\Property(
                    property: 'users',
                    type: 'array',
                    description: 'Spieler/Benutzer, die aktuell im Spiel sind (vereinfachte Darstellung).',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'integer', nullable: true, example: 1),
                            new OA\Property(property: 'username', type: 'string', nullable: true, example: 'alice'),
                        ]
                    )
                ),
                new OA\Property(property: 'message', type: 'string', nullable: true, example: 'Spiel nicht gefunden'),
            ]
        )
    )]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Unbekannte Spiel-ID (`NOT_FOUND`, schon vom Entity-Resolver). Meldet der Service ein fehlendes Spiel, antwortet der Endpoint mit `GAME_NOT_FOUND`.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[ApiResponse(headers: ['X-Accel-Buffering' => 'no'])]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Zugriff verweigert: `/api` ist per Firewall nur für ROLE_ADMIN offen, und der Code prüft zusätzlich die Spielteilnahme (`ACCESS_DENIED`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[Route('/api/invite/create/{id}', name: 'create_invitation', methods: ['POST'], format: 'json')]
    public function createInvitation(#[MapEntity(id: 'id')] Game $game, InvitationServiceInterface $invitationService): array
    {
        $payload = $invitationService->getInvitationPayload($game);
        if (!($payload['success'] ?? false)) {
            throw new GameNotFoundException();
        }

        return $payload;
    }

    /**
     * Joins an invitation by UUID and stores it in session.
     *
     * @param Invitation       $invitation
     * @param SessionInterface $session
     *
     * @return Response
     */
    #[OA\Parameter(
        name: 'uuid',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'string', format: 'uuid', example: 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')
    )]
    #[OA\Response(
        response: Response::HTTP_FOUND,
        description: 'Weiterleitung zum Frontend. Die Einladung wird in der Session gespeichert.',
        headers: [
            new OA\Header(header: 'Location', description: 'Frontend-URL', schema: new OA\Schema(type: 'string')),
        ]
    )]
    #[Security(name: null)]
    #[Route('/api/invite/join/{uuid}', name: 'join_invitation', methods: ['GET'], format: 'json')]
    public function joinInvitation(#[MapEntity(mapping: ['uuid' => 'uuid'])] Invitation $invitation, SessionInterface $session): Response
    {
        $gameId = $invitation->getGameId();
        if (null === $gameId) {
            $this->logger->warning('Invite join request is missing a game id.', [
                'invitationUuid' => $invitation->getUuid(),
            ]);
            throw new GameNotFoundException();
        }

        $invitationUuid = $invitation->getUuid();
        $redirectTarget = $this->frontendUrl.'/';

        $this->logger->info('Invite join request received.', [
            'gameId' => $gameId,
            'invitationUuid' => $invitationUuid,
        ]);

        try {
            $session->remove(InvitationSession::INVITATION_UUID);
            $session->set(InvitationSession::INVITATION_UUID, $invitationUuid);
            $session->set(InvitationSession::GAME_ID, $gameId);

            $this->logger->info('Invite join session prepared successfully.', [
                'gameId' => $gameId,
                'invitationUuid' => $invitationUuid,
                'redirect' => $redirectTarget,
            ]);

            return $this->redirect($redirectTarget);
        } catch (Throwable $throwable) {
            $this->logger->error('Invite join request failed.', [
                'gameId' => $gameId,
                'invitationUuid' => $invitationUuid,
                'exceptionClass' => $throwable::class,
                'exceptionMessage' => $throwable->getMessage(),
            ]);

            throw $throwable;
        }
    }
}
