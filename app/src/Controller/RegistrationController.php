<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Exception\Request\InvalidJsonBodyException;
use App\Http\Attribute\ApiResponse;
use App\Service\Registration\RegistrationServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller to handle user registration.
 */
final class RegistrationController extends AbstractController
{
    /**
     * Registers a user from JSON payload.
     *
     * @param Request                      $request
     * @param RegistrationServiceInterface $registrationService
     *
     * @return array<array-key, mixed>
     */
    #[ApiResponse]
    #[Route('/api/register', name: 'app_register', methods: ['POST'], format: 'json')]
    public function register(Request $request, RegistrationServiceInterface $registrationService): array
    {
        $data = json_decode($request->getContent(), true);
        if (JSON_ERROR_NONE !== json_last_error() || !is_array($data)) {
            throw new InvalidJsonBodyException();
        }

        $result = $registrationService->register($data);

        return $result;
    }
}
