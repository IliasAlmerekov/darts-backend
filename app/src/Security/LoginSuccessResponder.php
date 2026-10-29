<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Security;

use App\Dto\LoginSuccessDto;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds the login success body for GET /api/login/success, POST /api/login and POST /api/register.
 */
final readonly class LoginSuccessResponder
{
    /**
     * @param string $frontendUrl
     *
     * @psalm-suppress PossiblyUnusedMethod Reason: constructor is used by Symfony autowiring.
     */
    public function __construct(
        private string $frontendUrl,
    ) {
    }

    /**
     * @param LoginSuccessDto $loginSuccess
     *
     * @return JsonResponse
     */
    public function respond(LoginSuccessDto $loginSuccess): JsonResponse
    {
        $user = $loginSuccess->user;
        $body = [
            'success' => true,
            'roles' => $user->getStoredRoles(),
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'username' => $user->getUsername(),
            'redirect' => rtrim($this->frontendUrl, '/').$loginSuccess->frontendPath,
        ];
        if (null !== $loginSuccess->gameId) {
            $body['gameId'] = $loginSuccess->gameId;
        }
        if (null !== $loginSuccess->invitationError) {
            $body['invitationError'] = $loginSuccess->invitationError;
        }

        return new JsonResponse($body, Response::HTTP_OK);
    }
}
