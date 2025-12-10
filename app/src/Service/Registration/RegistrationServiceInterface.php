<?php

declare(strict_types=1);

namespace App\Service\Registration;

/**
 * Contract for user registration handling.
 */
interface RegistrationServiceInterface
{
    /**
     * Registers a user from submitted data.
     *
     * @param array<string, mixed> $data
     *
     * @return array{success:bool,message:string,status:int,redirect?:string,errors?:array<string,array<int,string>>}
     */
    public function register(array $data): array;
}
