<?php

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
    private string $frontendUrl;

    /**
     * @param InvitationServiceInterface $invitationService
     */
    public function __construct(
        private InvitationServiceInterface $invitationService,
    ) {
        $this->frontendUrl = rtrim($_ENV['FRONTEND_URL'] ?? 'http://localhost:5173', '/');
    }

    #[Override]
    public function buildLoginSuccessResponse(User $user, SessionInterface $session): Response
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return new JsonResponse([
                'success' => true,
                'roles' => $user->getStoredRoles(),
                'id' => $user->getId(),
                'username' => $user->getUserIdentifier(),
                'redirect' => $this->frontendUrl . '/start',
            ], Response::HTTP_OK, ['X-Accel-Buffering' => 'no']);
        }

        if ($session->has('invitation_uuid')) {
            return $this->invitationService->processInvitation($session, $user);
        }

        return new JsonResponse([
            'success' => true,
            'roles' => $user->getStoredRoles(),
            'id' => $user->getId(),
            'username' => $user->getUserIdentifier(),
            'redirect' => $this->frontendUrl . '/joined',
        ]);
    }
}
