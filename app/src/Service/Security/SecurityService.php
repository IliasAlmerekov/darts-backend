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
     * Builds login success response with JWT token and redirect.
     *
     * @param User        $user
     * @param string      $token
     * @param string|null $invitationUuid
     *
     * @return Response
     */
    #[Override]
    public function buildLoginSuccessResponse(User $user, string $token, ?string $invitationUuid = null): Response
    {
        $payload = $this->buildUserPayload($user);

        if (null !== $invitationUuid) {
            $invitationResponse = $this->invitationService->processInvitation($invitationUuid, $user);
            $invitationData = json_decode((string) $invitationResponse->getContent(), true);

            return new JsonResponse([
                'success' => true,
                'token' => $token,
                'roles' => $payload['roles'],
                'id' => $payload['id'],
                'email' => $payload['email'],
                'username' => $payload['username'],
                'redirect' => $invitationData['redirect'] ?? rtrim($this->frontendUrl, '/').'/joined',
            ], Response::HTTP_OK, ['X-Accel-Buffering' => 'no']);
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return new JsonResponse([
                'success' => true,
                'token' => $token,
                'roles' => $payload['roles'],
                'id' => $payload['id'],
                'email' => $payload['email'],
                'username' => $payload['username'],
                'redirect' => rtrim($this->frontendUrl, '/').'/start',
            ], Response::HTTP_OK, ['X-Accel-Buffering' => 'no']);
        }

        return new JsonResponse([
            'success' => true,
            'token' => $token,
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
