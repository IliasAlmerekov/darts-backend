<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\User;
use App\Exception\Game\GameAlreadyStartedException;
use App\Exception\Game\GameJoinNotAllowedException;
use App\Exception\Game\GameNotFoundException;
use App\Exception\Game\GameRoomFullException;
use App\Repository\GamePlayersRepositoryInterface;
use App\Service\Invitation\InvitationServiceInterface;
use Override;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Builds API responses for security flows.
 */
final readonly class SecurityService implements SecurityServiceInterface
{
    /**
     * @param InvitationServiceInterface     $invitationService
     * @param GamePlayersRepositoryInterface $gamePlayersRepository
     * @param string                         $frontendUrl
     */
    public function __construct(
        private InvitationServiceInterface $invitationService,
        private GamePlayersRepositoryInterface $gamePlayersRepository,
        private string $frontendUrl,
    ) {
    }

    /**
     * Builds login success response with proper redirect for role or invitation flow.
     *
     * @param User             $user
     * @param SessionInterface $session
     *
     * @return Response
     */
    #[Override]
    public function buildLoginSuccessResponse(User $user, SessionInterface $session): Response
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $this->buildResponse($user, '/start');
        }

        if ($session->has('invitation_uuid')) {
            try {
                return $this->invitationService->processInvitation($session, $user);
            } catch (GameAlreadyStartedException|GameJoinNotAllowedException|GameRoomFullException|GameNotFoundException $exception) {
                // The login itself succeeded; report why the join did not instead of failing the request.
                return $this->buildResponse($user, '/playerprofile', ['invitationError' => $exception->getErrorCode()]);
            }
        }

        // Without an invitation a player returns to their current game, if they have one.
        $playerId = $user->getId();
        $currentGame = null !== $playerId ? $this->gamePlayersRepository->findCurrentGameForPlayer($playerId) : null;
        if (null !== $currentGame) {
            return $this->buildResponse($user, '/joined', ['gameId' => $currentGame['gameId']]);
        }

        return $this->buildResponse($user, '/playerprofile');
    }

    /**
     * @param User                 $user
     * @param string               $frontendPath path on the frontend the client should open next
     * @param array<string, mixed> $extra        fields appended after `redirect`
     *
     * @return JsonResponse
     */
    private function buildResponse(User $user, string $frontendPath, array $extra = []): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'roles' => $user->getStoredRoles(),
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'username' => $user->getUsername(),
            'redirect' => rtrim($this->frontendUrl, '/').$frontendPath,
            ...$extra,
        ], Response::HTTP_OK);
    }
}
