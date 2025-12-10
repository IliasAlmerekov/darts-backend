<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Registration\RegistrationServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
     * @return Response
     */
    #[Route('/api/register', name: 'app_register', methods: ['POST'], format: 'json')]
    public function register(
        Request $request,
        RegistrationServiceInterface $registrationService,
    ): Response {
        $data = json_decode($request->getContent(), true);
        if (JSON_ERROR_NONE !== json_last_error() || !is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Ungültiger JSON-Body. Bitte überprüfe das Datenformat.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $result = $registrationService->register($data);

        return $this->json(
            $result,
            $result['status'] ?? Response::HTTP_OK
        );
    }
}
