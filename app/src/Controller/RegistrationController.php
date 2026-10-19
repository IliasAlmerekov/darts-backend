<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\RegistrationRequest;
use App\Http\Attribute\ApiResponse;
use App\Service\Registration\RegistrationServiceInterface;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller to handle user registration.
 */
#[OA\Tag(name: 'Registration')]
final class RegistrationController extends AbstractController
{
    public const string ROUTE = 'app_register';

    /**
     * Registers a player from JSON payload and logs them in.
     *
     * RegistrationRateLimitSubscriber counts the request before the payload is mapped, and
     * RegistrationErrorSubscriber renders mapping and validation failures.
     *
     * @param RegistrationRequest          $payload
     * @param RegistrationServiceInterface $registrationService
     *
     * @return Response
     *
     * @psalm-suppress PossiblyUnusedMethod Symfony route entry point
     */
    #[OA\Response(
        response: Response::HTTP_CREATED,
        description: 'Registrierung erfolgreich. Der Spieler ist angemeldet, der Body entspricht `/api/login/success`. Mit einer Einladung in der Session tritt er dem Spiel bei, die Einladung wird verbraucht.',
        headers: [
            new OA\Header(
                header: 'Set-Cookie',
                description: 'Session-Cookie und `REMEMBERME`-Cookie: der Spieler bleibt 7 Tage nach dem letzten Besuch angemeldet.',
                schema: new OA\Schema(type: 'string')
            ),
        ],
        content: new OA\JsonContent(
            type: 'object',
            required: ['success', 'roles', 'id', 'email', 'username', 'redirect'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string'), example: ['ROLE_PLAYER']),
                new OA\Property(property: 'id', type: 'integer', example: 1),
                new OA\Property(property: 'email', type: 'string', example: 'alice@example.com'),
                new OA\Property(property: 'username', type: 'string', example: 'alice'),
                new OA\Property(property: 'redirect', type: 'string', example: 'http://localhost:5173/playerprofile', description: 'Mit beigetretener Einladung: `/joined`. Sonst, auch bei `invitationError`: `/playerprofile`.'),
                new OA\Property(property: 'gameId', type: 'integer', example: 42, description: 'Nur mit Einladung: ID des beigetretenen Spiels.'),
                new OA\Property(property: 'invitationError', type: 'string', enum: ['GAME_ALREADY_STARTED', 'GAME_JOIN_NOT_ALLOWED', 'GAME_ROOM_FULL', 'GAME_NOT_FOUND'], example: 'GAME_ALREADY_STARTED', description: 'Nur wenn die gespeicherte Einladung nicht mehr nutzbar ist. Konto und Login bestehen trotzdem, `gameId` fehlt in diesem Fall.'),
            ]
        )
    )]
    #[OA\Response(
        response: Response::HTTP_BAD_REQUEST,
        description: 'Validierungsfehler: `errors` nennt je Feld (`email`, `username`, `plainPassword`) die Meldungen. Ein Body, der kein gültiges JSON ist, antwortet stattdessen mit `error: REQUEST_INVALID_JSON_BODY`.',
        content: new OA\JsonContent(
            type: 'object',
            required: ['success', 'message'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'error', type: 'string', example: 'REQUEST_INVALID_JSON_BODY', description: 'Nur wenn der Body kein gültiges JSON ist.'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(
                    property: 'errors',
                    type: 'object',
                    additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string'))
                ),
            ]
        )
    )]
    #[OA\Response(
        response: Response::HTTP_CONFLICT,
        description: 'E-Mail-Adresse oder Benutzername gehören schon zu einem Konto. Die Antwort nennt nicht, welches Feld betroffen ist. Es wird kein Konto angelegt und niemand angemeldet.',
        content: new OA\JsonContent(
            type: 'object',
            required: ['success', 'error', 'message'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'error', type: 'string', example: 'REGISTRATION_ACCOUNT_EXISTS'),
                new OA\Property(property: 'message', type: 'string', example: 'Ein Konto mit diesen Daten existiert bereits.'),
            ]
        )
    )]
    #[OA\Response(
        response: Response::HTTP_TOO_MANY_REQUESTS,
        description: 'Zu viele Registrierungsversuche von dieser IP-Adresse (höchstens 20 pro Stunde, jede Anfrage zählt). Es wird kein Konto angelegt, kein Login gestartet und keine Einladung verbraucht.',
        headers: [
            new OA\Header(header: 'Retry-After', description: 'Sekunden bis zum nächsten erlaubten Versuch.', schema: new OA\Schema(type: 'integer')),
        ],
        content: new OA\JsonContent(
            type: 'object',
            required: ['success', 'error', 'message'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'error', type: 'string', example: 'REGISTRATION_RATE_LIMITED'),
                new OA\Property(property: 'message', type: 'string'),
            ]
        )
    )]
    #[Security(name: null)]
    #[ApiResponse]
    #[Route('/api/register', name: self::ROUTE, methods: ['POST'], format: 'json')]
    public function register(
        #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        RegistrationRequest $payload,
        RegistrationServiceInterface $registrationService,
    ): Response {
        return $registrationService->register($payload);
    }
}
