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
use App\Entity\User;
use App\Http\Attribute\ApiResponse;
use App\Service\Invitation\InvitationServiceInterface;
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
    #[ApiResponse(headers: ['X-Accel-Buffering' => 'no'])]
    #[Route('/api/invite/create/{id}', name: 'create_invitation', format: 'json')]
    public function createInvitation(#[MapEntity(id: 'id')] Game $game, InvitationServiceInterface $invitationService): array
    {
        $payload = $invitationService->getInvitationPayload($game);
        $payload['status'] = ($payload['success'] ?? false) ? Response::HTTP_OK : Response::HTTP_NOT_FOUND;

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
    #[Route('api/invite/join/{uuid}', name: 'join_invitation', format: 'json')]
    public function joinInvitation(#[MapEntity(mapping: ['uuid' => 'uuid'])] Invitation $invitation, SessionInterface $session): Response
    {
        $session->remove('invitation_uuid');
        $session->set('invitation_uuid', $invitation->getUuid());
        $session->set('game_id', $invitation->getGameId());

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
    #[Route('api/invite/process', name: 'process_invitation')]
    public function processInvitation(SessionInterface $session, InvitationServiceInterface $invitationService): Response
    {
        $result = $invitationService->processInvitation($session, $this->getUser());

        return $result;
    }
}
