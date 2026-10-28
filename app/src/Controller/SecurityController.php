<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\Security\UserNotAuthenticatedException;
use App\Http\Attribute\ApiResponse;
use App\Service\Security\SecurityServiceInterface;
use LogicException;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller to handle user login and logout.
 *
 * @psalm-suppress UnusedClass Routed by Symfony framework
 */
#[OA\Tag(name: 'Authentication')]
final class SecurityController extends AbstractController
{
    /**
     * Returns the login state of a logged-in user; without a session it answers 401.
     *
     * @return array<array-key, mixed>
     */
    #[OA\RequestBody(
        required: false,
        description: 'Login-Daten für POST-Requests (Standard Symfony-Form-Felder). _username erwartet die E-Mail-Adresse.',
        content: new OA\MediaType(
            mediaType: 'application/x-www-form-urlencoded',
            schema: new OA\Schema(
                type: 'object',
                properties: [
                    new OA\Property(property: '_username', type: 'string', example: 'alice@example.com'),
                    new OA\Property(property: '_password', type: 'string', example: 'secret'),
                    new OA\Property(property: '_csrf_token', type: 'string', example: 'csrf-token'),
                ]
            )
        )
    )]
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Login-Status. Ein erfolgreicher API-POST antwortet mit dem Body von `/api/login/success`. GET mit bestehender Session verweist auf `/api/login/success`.',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'id', type: 'integer', nullable: true, example: 1),
                new OA\Property(property: 'email', type: 'string', nullable: true, example: 'alice@example.com'),
                new OA\Property(property: 'username', type: 'string', nullable: true, example: 'alice'),
                new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string'), example: ['ROLE_PLAYER']),
                new OA\Property(property: 'redirect', type: 'string', nullable: true, example: 'http://localhost:5173/playerprofile', description: 'Nach POST das Frontend-Ziel wie bei `/api/login/success` (`/start`, `/joined` oder `/playerprofile`). Nach GET mit bestehender Session: `/api/login/success`.'),
                new OA\Property(property: 'gameId', type: 'integer', example: 42, description: 'Nur nach POST mit Redirect auf `/joined`: ID des beigetretenen oder des aktuellen Spiels.'),
                new OA\Property(property: 'invitationError', type: 'string', enum: ['GAME_ALREADY_STARTED', 'GAME_JOIN_NOT_ALLOWED', 'GAME_ROOM_FULL', 'GAME_NOT_FOUND'], example: 'GAME_ALREADY_STARTED', description: 'Nur wenn die gespeicherte Einladung nicht mehr nutzbar ist. Der Login war erfolgreich, `gameId` fehlt in diesem Fall.'),
            ]
        )
    )]
    #[OA\Response(
        response: Response::HTTP_UNAUTHORIZED,
        description: 'Bei POST: Anmeldung fehlgeschlagen (`LOGIN_INVALID_CREDENTIALS`), für jede Ursache gleich, auch bei unbekannter E-Mail. Bei GET ohne Session: `AUTHENTICATION_REQUIRED`.',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
    )]
    #[OA\Response(
        response: Response::HTTP_TOO_MANY_REQUESTS,
        description: 'Zu viele fehlgeschlagene Anmeldungen (`LOGIN_RATE_LIMITED`; 5 pro Minute je E-Mail und IP-Adresse, dazu ein weiteres Limit pro IP). Nur bei POST.',
        headers: [
            new OA\Header(header: 'Retry-After', description: 'Sekunden bis zum nächsten erlaubten Versuch, wenn der Limiter sie nennt.', schema: new OA\Schema(type: 'integer')),
        ],
        content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
    )]
    #[Security(name: null)]
    #[ApiResponse]
    #[Route(path: '/api/login', name: 'app_login', methods: ['GET', 'POST'], format: 'json')]
    public function login(): array
    {
        $user = $this->getUser();
        if ($user) {
            $id = method_exists($user, 'getId') ? $user->getId() : null;
            $email = $user instanceof User ? $user->getEmail() : $user->getUserIdentifier();
            $username = $user instanceof User ? $user->getUsername() : null;

            return [
                'success' => true,
                'id' => $id,
                'email' => $email,
                'username' => $username,
                'roles' => $user instanceof User ? $user->getStoredRoles() : $user->getRoles(),
                'redirect' => '/api/login/success',
            ];
        }

        throw new UserNotAuthenticatedException();
    }

    /**
     * Handles post-login redirection logic for API clients.
     *
     * @param Request                  $request
     * @param SecurityServiceInterface $securityService
     *
     * @return Response
     *
     * @psalm-suppress PossiblyUnusedMethod Symfony route entry point
     */
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Login-Erfolg inkl. Redirect-Ziel fürs Frontend.',
        content: new OA\JsonContent(
            type: 'object',
            required: ['success', 'redirect'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string'), example: ['ROLE_PLAYER']),
                new OA\Property(property: 'id', type: 'integer', nullable: true, example: 1),
                new OA\Property(property: 'email', type: 'string', nullable: true, example: 'alice@example.com'),
                new OA\Property(property: 'username', type: 'string', nullable: true, example: 'alice'),
                new OA\Property(property: 'redirect', type: 'string', example: 'http://localhost:5173/playerprofile', description: 'Admin: `/start`. Spieler mit Einladung oder mit aktuellem Spiel (`lobby` oder `started`): `/joined`. Sonst, auch bei `invitationError`: `/playerprofile`.'),
                new OA\Property(property: 'gameId', type: 'integer', example: 42, description: 'Nur bei Redirect auf `/joined`: ID des beigetretenen oder des aktuellen Spiels.'),
                new OA\Property(property: 'invitationError', type: 'string', enum: ['GAME_ALREADY_STARTED', 'GAME_JOIN_NOT_ALLOWED', 'GAME_ROOM_FULL', 'GAME_NOT_FOUND'], example: 'GAME_ALREADY_STARTED', description: 'Nur wenn die gespeicherte Einladung nicht mehr nutzbar ist. Der Login war erfolgreich, `gameId` fehlt in diesem Fall.'),
            ]
        )
    )]
    #[OA\Response(response: Response::HTTP_UNAUTHORIZED, description: 'Keine Session (`AUTHENTICATION_REQUIRED`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[Route('/api/login/success', name: 'login_success', methods: ['GET'], format: 'json')]
    public function loginSuccess(Request $request, SecurityServiceInterface $securityService): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new UserNotAuthenticatedException();
        }

        return $securityService->buildLoginSuccessResponse($user, $request->getSession());
    }

    /**
     * Logs out the current user.
     *
     * @return void
     *
     * @psalm-suppress PossiblyUnusedMethod Handled by Symfony firewall logout
     */
    #[OA\Response(response: Response::HTTP_NO_CONTENT, description: 'Abgemeldet, die Session ist beendet. Die Antwort hat keinen Body.')]
    #[Route(path: '/api/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): void
    {
        throw new LogicException(
            'This method can be blank - it will be intercepted by the logout key on your firewall.'
        );
    }

    /**
     * Returns CSRF tokens for authentication flows.
     *
     * @param CsrfTokenManagerInterface $csrfTokenManager
     *
     * @return array{success:bool,tokens:array<string,string>}
     */
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'CSRF-Tokens für Login/Registration.',
        content: new OA\JsonContent(
            type: 'object',
            required: ['success', 'tokens'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'tokens',
                    type: 'object',
                    additionalProperties: new OA\AdditionalProperties(type: 'string'),
                    example: ['authenticate' => 'csrf-token', 'user_registration' => 'csrf-token']
                ),
            ]
        )
    )]
    #[Security(name: null)]
    #[ApiResponse]
    #[Route(path: '/api/csrf', name: 'app_csrf_tokens', methods: ['GET'], format: 'json')]
    public function csrfTokens(CsrfTokenManagerInterface $csrfTokenManager): array
    {
        return [
            'success' => true,
            'tokens' => [
                'authenticate' => $csrfTokenManager->getToken('authenticate')->getValue(),
                'user_registration' => $csrfTokenManager->getToken('user_registration')->getValue(),
            ],
        ];
    }
}
