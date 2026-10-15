<?php

declare(strict_types=1);

namespace App\Service\Registration;

use Symfony\Component\HttpFoundation\Response;

/**
 * Contract for user registration handling.
 */
interface RegistrationServiceInterface
{
    /**
     * Registers a player from submitted data and logs them in.
     *
     * On success the response is the login success response with status 201. On a validation
     * failure nothing is saved and the result carries the field errors.
     *
     * @param array<string, mixed> $data
     *
     * @return Response|array{success:false,message:string,status:int,errors:array<string,array<int,string>>}
     */
    public function register(array $data): Response|array;
}
