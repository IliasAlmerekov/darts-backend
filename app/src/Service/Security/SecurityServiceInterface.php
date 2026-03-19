<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contract for security-related response handling.
 */
interface SecurityServiceInterface
{
    /**
     * Builds a login success response for the given user.
     *
     * @param User        $user
     * @param string      $token
     * @param string|null $invitationUuid
     *
     * @return Response
     */
    public function buildLoginSuccessResponse(User $user, string $token, ?string $invitationUuid = null): Response;
}
