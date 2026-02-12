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
use App\Http\Attribute\ApiResponse;
use App\Service\Invitation\InvitationServiceInterface;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * This class handles invitation-related actions such as creating invitations,
 * joining via invitation links, and processing invitations.
 * Also get as JSON responses for API requests.
 */
#[OA\Tag(name: 'Invitations')]
final class InvitationController extends AbstractController
{
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
            required: ['success', 'status'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'status', type: 'integer', example: 200),
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
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Spiel nicht gefunden.')]
    #[ApiResponse(headers: ['X-Accel-Buffering' => 'no'])]
    #[Route('/api/invite/create/{id}', name: 'create_invitation', methods: ['POST'], format: 'json')]
    public function createInvitation(#[MapEntity(id: 'id')] Game $game, InvitationServiceInterface $invitationService): array
    {
        $payload = $invitationService->getInvitationPayload($game);
        $payload['status'] = ($payload['success'] ?? false) ? Response::HTTP_OK : Response::HTTP_NOT_FOUND;

        return $payload;
    }

    /**
     * Joins an invitation by UUID and stores it in session.
     *
     * @param Invitation                 $invitation
     * @param SessionInterface           $session
     * @param InvitationServiceInterface $invitationService
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
    #[OA\Response(
        response: Response::HTTP_CONFLICT,
        description: 'Spiel kann nicht gejoint werden (z. B. nicht mehr in Lobby oder Raum bereits voll).'
    )]
    #[Security(name: null)]
    #[Route('api/invite/join/{uuid}', name: 'join_invitation', format: 'json')]
    public function joinInvitation(#[MapEntity(mapping: ['uuid' => 'uuid'])] Invitation $invitation, SessionInterface $session, InvitationServiceInterface $invitationService): Response
    {
        $gameId = $invitation->getGameId();
        if (null === $gameId) {
            throw new GameNotFoundException();
        }

        $invitationService->assertGameJoinable($gameId);
        $session->remove('invitation_uuid');
        $session->set('invitation_uuid', $invitation->getUuid());
        $session->set('game_id', $gameId);

        $frontendUrl = rtrim($_ENV['FRONTEND_URL'] ?? 'http://localhost:5173', '/');

        return $this->redirect($frontendUrl.'/');
    }

    /**
     * Processes invitation stored in session for the current user.
     *
     * @param SessionInterface           $session
     * @param InvitationServiceInterface $invitationService
     *
     * @return Response
     */
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Einladung wurde verarbeitet (User dem Spiel hinzugefügt) und Redirect fürs Frontend zurückgegeben.',
        content: new OA\JsonContent(
            type: 'object',
            required: ['success', 'redirect'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'redirect', type: 'string', example: 'http://localhost:5173/joined'),
            ]
        )
    )]
    #[OA\Response(
        response: Response::HTTP_UNAUTHORIZED,
        description: 'User ist nicht authentifiziert oder Session ist ungültig.',
        content: new OA\JsonContent(
            type: 'object',
            required: ['success', 'redirect'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'redirect', type: 'string', example: '/login'),
            ]
        )
    )]
    #[OA\Response(
        response: Response::HTTP_BAD_REQUEST,
        description: 'Session enthält keine game_id (z. B. Einladung nicht zuerst gejoint).',
        content: new OA\JsonContent(
            type: 'object',
            required: ['success', 'redirect'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'redirect', type: 'string', example: '/start'),
            ]
        )
    )]
    #[OA\Response(
        response: Response::HTTP_CONFLICT,
        description: 'Spiel kann nicht gejoint werden (z. B. nicht mehr in Lobby oder Raum bereits voll).'
    )]
    #[Route('api/invite/process', name: 'process_invitation', methods: ['POST'], format: 'json')]
    public function processInvitation(SessionInterface $session, InvitationServiceInterface $invitationService): Response
    {
        $result = $invitationService->processInvitation($session, $this->getUser());

        return $result;
    }
}
