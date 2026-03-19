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
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * This class handles invitation-related actions such as creating invitations,
 * joining via invitation links, and processing invitations.
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
        description: 'Invitation link created or already existed.',
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
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'integer', nullable: true, example: 1),
                            new OA\Property(property: 'username', type: 'string', nullable: true, example: 'alice'),
                        ]
                    )
                ),
            ]
        )
    )]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Game not found.')]
    #[ApiResponse(headers: ['X-Accel-Buffering' => 'no'])]
    #[Route('/api/invite/create/{id}', name: 'create_invitation', methods: ['POST'], format: 'json')]
    public function createInvitation(#[MapEntity(id: 'id')] Game $game, InvitationServiceInterface $invitationService): array
    {
        $payload = $invitationService->getInvitationPayload($game);
        $payload['status'] = ($payload['success'] ?? false) ? Response::HTTP_OK : Response::HTTP_NOT_FOUND;

        return $payload;
    }

    /**
     * Joins an invitation by UUID — redirects to frontend login with invitation params.
     *
     * @param Invitation                 $invitation
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
        description: 'Redirects to frontend login with invitation_uuid query param.',
        headers: [
            new OA\Header(header: 'Location', description: 'Frontend login URL with invitation params', schema: new OA\Schema(type: 'string')),
        ]
    )]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'Game cannot be joined.')]
    #[Security(name: null)]
    #[Route('api/invite/join/{uuid}', name: 'join_invitation', format: 'json')]
    public function joinInvitation(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Invitation $invitation,
        InvitationServiceInterface $invitationService,
    ): Response {
        $gameId = $invitation->getGameId();
        if (null === $gameId) {
            $this->logger->warning('Invite join request is missing a game id.', [
                'invitationUuid' => $invitation->getUuid(),
            ]);
            throw new GameNotFoundException();
        }

        $invitationUuid = (string) $invitation->getUuid();

        $this->logger->info('Invite join request received.', [
            'gameId' => $gameId,
            'invitationUuid' => $invitationUuid,
        ]);

        try {
            $invitationService->assertGameJoinable($gameId);

            $redirectTarget = $this->frontendUrl.'/?invitation_uuid='.$invitationUuid.'&game_id='.$gameId;

            $this->logger->info('Invite join redirect prepared.', [
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

    /**
     * Processes invitation for the current authenticated user.
     *
     * @param Request                    $request
     * @param InvitationServiceInterface $invitationService
     *
     * @return Response
     */
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            type: 'object',
            required: ['invitation_uuid'],
            properties: [
                new OA\Property(property: 'invitation_uuid', type: 'string', example: 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'),
            ]
        )
    )]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Invitation processed, user added to game.',
        content: new OA\JsonContent(
            type: 'object',
            required: ['success', 'redirect'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'redirect', type: 'string', example: 'http://localhost:5173/joined'),
            ]
        )
    )]
    #[OA\Response(response: Response::HTTP_UNAUTHORIZED, description: 'User not authenticated.')]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Missing invitation_uuid.')]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'Game cannot be joined.')]
    #[Route('api/invite/process', name: 'process_invitation', methods: ['POST'], format: 'json')]
    public function processInvitation(Request $request, InvitationServiceInterface $invitationService): Response
    {
        $user = $this->getUser();
        $userId = is_object($user) && method_exists($user, 'getId') ? $user->getId() : null;

        $data = json_decode($request->getContent(), true) ?? [];
        $invitationUuid = isset($data['invitation_uuid']) && '' !== $data['invitation_uuid']
            ? (string) $data['invitation_uuid']
            : null;

        $this->logger->info('Invite process request received.', [
            'userId' => $userId,
            'invitationUuid' => $invitationUuid,
        ]);

        try {
            $result = $invitationService->processInvitation($invitationUuid, $user);

            $this->logger->info('Invite process request completed.', [
                'userId' => $userId,
                'statusCode' => $result->getStatusCode(),
            ]);

            return $result;
        } catch (Throwable $throwable) {
            $this->logger->error('Invite process request failed.', [
                'userId' => $userId,
                'exceptionClass' => $throwable::class,
                'exceptionMessage' => $throwable->getMessage(),
            ]);

            throw $throwable;
        }
    }
}
