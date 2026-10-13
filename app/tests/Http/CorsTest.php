<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cross-origin contract for the conditional GET on /api/game/{gameId}: a browser on
 * another origin must be able to send If-None-Match and read the version headers.
 */
final class CorsTest extends WebTestCase
{
    private const string ORIGIN = 'http://localhost:3000';

    public function testPreflightAllowsIfNoneMatch(): void
    {
        $client = static::createClient();

        $client->request('OPTIONS', '/api/game/1', server: [
            'HTTP_ORIGIN' => self::ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'if-none-match',
        ]);

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(self::ORIGIN, $response->headers->get('Access-Control-Allow-Origin'));
        self::assertContains('if-none-match', $this->headerList($response->headers->get('Access-Control-Allow-Headers')));
    }

    public function testGameResponseExposesVersionHeaders(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/game/1', server: ['HTTP_ORIGIN' => self::ORIGIN]);

        $exposed = $this->headerList($client->getResponse()->headers->get('Access-Control-Expose-Headers'));
        self::assertContains('etag', $exposed);
        self::assertContains('x-game-state-version', $exposed);
        self::assertContains('link', $exposed);
    }

    /**
     * @return list<string>
     */
    private function headerList(?string $value): array
    {
        return array_map(static fn (string $name): string => strtolower(trim($name)), explode(',', (string) $value));
    }
}
