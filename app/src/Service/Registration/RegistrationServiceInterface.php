<?php

declare(strict_types=1);

namespace App\Service\Registration;

use App\Dto\RegistrationRequest;
use App\Exception\Registration\RegistrationAccountExistsException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contract for user registration handling.
 */
interface RegistrationServiceInterface
{
    /**
     * Registers a player from a validated request and logs them in.
     *
     * On success the response is the login success response with status 201.
     *
     * @param RegistrationRequest $request
     *
     * @return Response
     *
     * @throws RegistrationAccountExistsException when the email or the username is taken; nobody is logged in
     */
    public function register(RegistrationRequest $request): Response;
}
