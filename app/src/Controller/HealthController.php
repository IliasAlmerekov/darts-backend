<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Exception\Health\DatabaseUnavailableException;
use Doctrine\DBAL\Connection;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/** Responds to liveness and readiness probes. */
#[OA\Tag(name: 'Invitations')]
final class HealthController extends AbstractController
{
    /** @param LoggerInterface $logger */
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Lightweight readiness endpoint for proxy health checks.
     *
     * @return JsonResponse
     */
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'Backend is reachable and ready to accept API traffic.',
        content: new OA\JsonContent(
            type: 'object',
            required: ['success', 'status'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'status', type: 'string', example: 'ok'),
            ]
        )
    )]
    #[Security(name: null)]
    #[Route('/api/health', name: 'api_health', methods: ['GET'], format: 'json')]
    public function health(): JsonResponse
    {
        return $this->json([
            'success' => true,
            'status' => 'ok',
        ]);
    }

    /**
     * Readiness endpoint: answers 200 only while the database answers a trivial query.
     *
     * @param Connection $connection
     *
     * @return JsonResponse
     *
     * @throws DatabaseUnavailableException
     */
    #[OA\Response(
        response: Response::HTTP_OK,
        description: 'The database answers; the backend can serve API traffic.',
        content: new OA\JsonContent(
            type: 'object',
            required: ['success', 'status'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'status', type: 'string', example: 'ready'),
            ]
        )
    )]
    #[OA\Response(response: Response::HTTP_SERVICE_UNAVAILABLE, description: 'Die Datenbankprüfung ist fehlgeschlagen (`DATABASE_UNAVAILABLE`), ohne Fehlerdetails.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[Security(name: null)]
    #[Route('/api/ready', name: 'api_ready', methods: ['GET'], format: 'json')]
    public function ready(Connection $connection): JsonResponse
    {
        try {
            $connection->fetchOne('SELECT 1');
        } catch (Throwable $exception) {
            // Only the log gets the cause: its text can carry the DSN or host.
            $this->logger->error('Readiness check failed: database unavailable.', ['exception' => $exception]);

            throw new DatabaseUnavailableException();
        }

        return $this->json(['success' => true, 'status' => 'ready']);
    }
}
