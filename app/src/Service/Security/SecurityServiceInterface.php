<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Contract for security-related response handling.
 */
interface SecurityServiceInterface
{
    /**
     * Builds a login success response for the given user/session.
     *
     * @param User             $user
     * @param SessionInterface $session
     *
     * @return Response
     */
    public function buildLoginSuccessResponse(User $user, SessionInterface $session): Response;
}
