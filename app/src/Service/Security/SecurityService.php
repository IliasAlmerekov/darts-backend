<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\User;
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
     * @param InvitationServiceInterface $invitationService
     * @param string                     $frontendUrl
     */
    public function __construct(
        private InvitationServiceInterface $invitationService,
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
        $payload = $this->buildUserPayload($user);

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return new JsonResponse([
                'success' => true,
                'roles' => $payload['roles'],
                'id' => $payload['id'],
                'email' => $payload['email'],
                'username' => $payload['username'],
                'redirect' => rtrim($this->frontendUrl, '/').'/start',
            ], Response::HTTP_OK, ['X-Accel-Buffering' => 'no']);
        }

        if ($session->has('invitation_uuid')) {
            return $this->invitationService->processInvitation($session, $user);
        }

        return new JsonResponse([
            'success' => true,
            'roles' => $payload['roles'],
            'id' => $payload['id'],
            'email' => $payload['email'],
            'username' => $payload['username'],
            'redirect' => rtrim($this->frontendUrl, '/').'/joined',
        ]);
    }

    /**
     * @param User $user
     *
     * @return array{id:int|null,email:string|null,username:string|null,roles:list<string>}
     */
    private function buildUserPayload(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'username' => $user->getUsername(),
            'roles' => $user->getStoredRoles(),
        ];
    }
}
