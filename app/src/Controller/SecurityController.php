<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Http\Attribute\ApiResponse;
use App\Repository\UserRepositoryInterface;
use App\Service\Security\SecurityServiceInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller to handle user login and logout.
 *
 * @psalm-suppress UnusedClass Routed by Symfony framework
 */
#[OA\Tag(name: 'Authentication')]
final class SecurityController extends AbstractController
{
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'email', type: 'string', example: 'alice@example.com'),
                new OA\Property(property: 'password', type: 'string', example: 'secret'),
                new OA\Property(property: 'invitation_uuid', type: 'string', nullable: true, example: 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'),
            ]
        )
    )]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Login successful. Returns JWT token and user info.',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'token', type: 'string', example: 'eyJ...'),
                new OA\Property(property: 'id', type: 'integer', nullable: true, example: 1),
                new OA\Property(property: 'email', type: 'string', nullable: true, example: 'alice@example.com'),
                new OA\Property(property: 'username', type: 'string', nullable: true, example: 'alice'),
                new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'redirect', type: 'string', nullable: true, example: '/start'),
            ]
        )
    )]
    #[OA\Response(response: Response::HTTP_UNAUTHORIZED, description: 'Invalid credentials.')]
    #[Security(name: null)]
    #[Route(path: '/api/login', name: 'app_login', methods: ['POST'], format: 'json')]
    public function login(
        Request $request,
        UserRepositoryInterface $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        JWTTokenManagerInterface $jwtManager,
        SecurityServiceInterface $securityService,
    ): Response {
        $data = json_decode($request->getContent(), true) ?? [];
        $email = (string) ($data['email'] ?? $data['_username'] ?? '');
        $password = (string) ($data['password'] ?? $data['_password'] ?? '');
        $invitationUuid = isset($data['invitation_uuid']) && '' !== $data['invitation_uuid']
            ? (string) $data['invitation_uuid']
            : null;

        $user = $userRepository->findOneByEmail($email);
        if (!$user instanceof User || !$passwordHasher->isPasswordValid($user, $password)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Invalid credentials.',
                'status' => Response::HTTP_UNAUTHORIZED,
            ], Response::HTTP_UNAUTHORIZED);
        }

        $token = $jwtManager->create($user);

        return $securityService->buildLoginSuccessResponse($user, $token, $invitationUuid);
    }

    /**
     * Returns current authenticated user info.
     *
     * @return array<array-key, mixed>
     */
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Current user info.',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'id', type: 'integer', nullable: true, example: 1),
                new OA\Property(property: 'email', type: 'string', nullable: true, example: 'alice@example.com'),
                new OA\Property(property: 'username', type: 'string', nullable: true, example: 'alice'),
                new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string')),
            ]
        )
    )]
    #[Security(name: null)]
    #[ApiResponse]
    #[Route(path: '/api/login', name: 'app_login_get', methods: ['GET'], format: 'json')]
    public function loginStatus(): array
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            return [
                'success' => true,
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'username' => $user->getUsername(),
                'roles' => $user->getStoredRoles(),
            ];
        }

        return ['success' => false, 'error' => 'Not authenticated', 'status' => Response::HTTP_UNAUTHORIZED];
    }

    /**
     * Logs out — with JWT, logout is client-side (discard token).
     *
     * @return Response
     *
     * @psalm-suppress PossiblyUnusedMethod Symfony route entry point
     */
    #[OA\Response(response: Response::HTTP_OK, description: 'Logged out.')]
    #[Route(path: '/api/logout', name: 'app_logout', methods: ['POST'], format: 'json')]
    public function logout(): Response
    {
        return new JsonResponse(['success' => true]);
    }
}
