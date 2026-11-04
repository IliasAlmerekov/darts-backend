<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Support\TestUserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every route that takes a game id answers an unknown id with 404 GAME_NOT_FOUND (#258).
 * A miss on another entity and an unknown route keep the generic 404 NOT_FOUND.
 */
final class GameNotFoundEndpointTest extends WebTestCase
{
    private const int UNKNOWN_GAME_ID = 2147483000;

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function gameRoutes(): iterable
    {
        $id = self::UNKNOWN_GAME_ID;

        yield 'game state' => [Request::METHOD_GET, "/api/game/{$id}", null];
        yield 'start' => [Request::METHOD_POST, "/api/game/{$id}/start", '{}'];
        yield 'finish' => [Request::METHOD_POST, "/api/game/{$id}/finish", null];
        yield 'reopen' => [Request::METHOD_PATCH, "/api/game/{$id}/reopen", null];
        yield 'finished' => [Request::METHOD_GET, "/api/game/{$id}/finished", null];
        yield 'abort' => [Request::METHOD_PATCH, "/api/game/{$id}/abort", null];
        yield 'settings read' => [Request::METHOD_GET, "/api/game/{$id}/settings", null];
        yield 'settings update' => [Request::METHOD_PATCH, "/api/game/{$id}/settings", '{}'];
        yield 'throw' => [Request::METHOD_POST, "/api/game/{$id}/throw/delta", '{"playerId":1,"value":20}'];
        yield 'undo throw' => [Request::METHOD_DELETE, "/api/game/{$id}/throw/delta", null];
        yield 'invitation create' => [Request::METHOD_POST, "/api/invite/create/{$id}", null];
        yield 'room leave' => [Request::METHOD_DELETE, "/api/room/{$id}?playerId=1", null];
        yield 'room guest' => [Request::METHOD_POST, "/api/room/{$id}/guest", '{"username":"Guest"}'];
        yield 'room positions' => [Request::METHOD_POST, "/api/room/{$id}/positions", '{"positions":[]}'];
        yield 'room stream' => [Request::METHOD_GET, "/api/room/{$id}/stream", null];
        yield 'room rematch' => [Request::METHOD_POST, "/api/room/{$id}/rematch", null];
    }

    #[DataProvider('gameRoutes')]
    public function testUnknownGameIdAnswersGameNotFound(string $method, string $uri, ?string $body): void
    {
        $client = $this->adminClient();
        $client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, (string) $client->getResponse()->getContent());
        self::assertSame(
            '{"success":false,"error":"GAME_NOT_FOUND","message":"Game not found"}',
            $client->getResponse()->getContent(),
        );
    }

    public function testUnknownInvitationKeepsTheGenericNotFound(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/api/invite/join/00000000-0000-4000-8000-000000000000');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, (string) $client->getResponse()->getContent());
        self::assertSame('NOT_FOUND', $this->errorCode($client));
    }

    public function testUnknownRouteKeepsTheGenericNotFound(): void
    {
        $client = $this->adminClient();
        $client->request(Request::METHOD_GET, '/api/game/'.self::UNKNOWN_GAME_ID.'/nope');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, (string) $client->getResponse()->getContent());
        self::assertSame('NOT_FOUND', $this->errorCode($client));
    }

    private function adminClient(): KernelBrowser
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = TestUserFactory::create($entityManager, 'gamenotfound', 'admin', ['ROLE_ADMIN']);
        $entityManager->flush();
        $client->loginUser($admin);

        return $client;
    }

    private function errorCode(KernelBrowser $client): mixed
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body['error'] ?? null;
    }
}
