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
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller to handle user registration.
 */
#[OA\Tag(name: 'Registration')]
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
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            type: 'object',
            required: ['username', 'email', 'plainPassword'],
            properties: [
                new OA\Property(property: 'username', type: 'string', example: 'alice'),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alice@example.com'),
                new OA\Property(property: 'plainPassword', type: 'string', format: 'password', example: 'secret'),
            ]
        )
    )]
    #[OA\Response(
        response: Response::HTTP_CREATED,
        description: 'Registrierung erfolgreich.',
        content: new OA\JsonContent(
            type: 'object',
            required: ['success', 'message', 'status'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Registrierung erfolgreich'),
                new OA\Property(property: 'redirect', type: 'string', nullable: true, example: '/'),
                new OA\Property(property: 'status', type: 'integer', example: 201),
            ]
        )
    )]
    #[OA\Response(
        response: Response::HTTP_BAD_REQUEST,
        description: 'Validierungsfehler.',
        content: new OA\JsonContent(
            type: 'object',
            required: ['success', 'message', 'status'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'status', type: 'integer', example: 400),
                new OA\Property(
                    property: 'errors',
                    type: 'object',
                    additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string'))
                ),
            ]
        )
    )]
    #[Security(name: null)]
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
