<?php

declare(strict_types=1);

namespace App\Service\Registration;

use App\Dto\RegistrationRequest;
use App\Entity\User;
use App\Exception\Registration\RegistrationAccountExistsException;

/**
 * Contract for user registration handling.
 */
interface RegistrationServiceInterface
{
    /**
     * Registers a player from a validated request.
     *
     * @param RegistrationRequest $request
     *
     * @return User the persisted player
     *
     * @throws RegistrationAccountExistsException when the email or the username is taken
     */
    public function register(RegistrationRequest $request): User;
}
