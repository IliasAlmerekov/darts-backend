<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/ready: readiness depends on the database, unlike the liveness check /api/health.
 * The 503 is the shared error body of ADR 0006.
 */
final class ReadinessEndpointTest extends WebTestCase
{
    private const string URI = '/api/ready';
    private const string SECRET_DETAIL = 'SQLSTATE[HY000] [2002] connection refused to db-secret-host:3306';

    public function testReadyWhenDatabaseAnswers(): void
    {
        $client = static::createClient();
        $client->request('GET', self::URI);

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertSame(
            ['success' => true, 'status' => 'ready'],
            json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testUnavailableWithoutLeakingDetailsWhenDatabaseFails(): void
    {
        $client = static::createClient();
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willThrowException(new RuntimeException(self::SECRET_DETAIL));
        static::getContainer()->set('doctrine.dbal.default_connection', $connection);

        $client->request('GET', self::URI);
        $body = (string) $client->getResponse()->getContent();

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $client->getResponse()->getStatusCode());
        self::assertSame(
            ['success' => false, 'error' => 'DATABASE_UNAVAILABLE', 'message' => 'The database is unavailable.'],
            json_decode($body, true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString('secret', $body);
    }
}
