<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Exception\Registration\RegistrationRateLimitedException;
use App\Exception\Request\InvalidJsonBodyException;
use App\Http\Attribute\ApiResponse;
use App\Service\Registration\RegistrationServiceInterface;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller to handle user registration.
 */
#[OA\Tag(name: 'Registration')]
final class RegistrationController extends AbstractController
{
    /**
     * Registers a player from JSON payload and logs them in.
     *
     * @param Request                      $request
     * @param RegistrationServiceInterface $registrationService
     * @param RateLimiterFactoryInterface  $registrationLimiter
     *
     * @return Response|array<array-key, mixed>
     */
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            type: 'object',
            required: ['username', 'email', 'plainPassword'],
            properties: [
                new OA\Property(property: 'username', type: 'string', example: 'alice'),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alice@example.com'),
                new OA\Property(property: 'plainPassword', type: 'string', format: 'password', example: 'secret123'),
            ]
        )
    )]
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
                new OA\Property(property: 'redirect', type: 'string', example: 'http://localhost:5173/joined'),
                new OA\Property(property: 'gameId', type: 'integer', example: 42, description: 'Nur mit Einladung: ID des beigetretenen Spiels.'),
                new OA\Property(property: 'invitationError', type: 'string', enum: ['GAME_ALREADY_STARTED', 'GAME_JOIN_NOT_ALLOWED', 'GAME_ROOM_FULL', 'GAME_NOT_FOUND'], example: 'GAME_ALREADY_STARTED', description: 'Nur wenn die gespeicherte Einladung nicht mehr nutzbar ist. Konto und Login bestehen trotzdem, `gameId` fehlt in diesem Fall.'),
            ]
        )
    )]
    #[OA\Response(
        response: Response::HTTP_BAD_REQUEST,
        description: 'Validierungsfehler.',
        content: new OA\JsonContent(
            type: 'object',
            required: ['success', 'message'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
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
    #[Route('/api/register', name: 'app_register', methods: ['POST'], format: 'json')]
    public function register(
        Request $request,
        RegistrationServiceInterface $registrationService,
        RateLimiterFactoryInterface $registrationLimiter,
    ): Response|array {
        // Before any registration work: every request counts, whatever its outcome.
        $rateLimit = $registrationLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$rateLimit->isAccepted()) {
            throw new RegistrationRateLimitedException($rateLimit->getRetryAfter());
        }

        $data = json_decode($request->getContent(), true);
        if (JSON_ERROR_NONE !== json_last_error() || !is_array($data)) {
            throw new InvalidJsonBodyException();
        }

        return $registrationService->register($data);
    }
}
