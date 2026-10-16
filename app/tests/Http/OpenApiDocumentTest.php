<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Http;

use App\Tests\Support\DevFunctionalKernel;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;

/**
 * The generated OpenAPI document (/api/doc.json) is a contract with the frontend. It must
 * list the methods the routes accept, leave out body fields the API view subscriber strips,
 * and document the error statuses the code can return.
 */
final class OpenApiDocumentTest extends WebTestCase
{
    /**
     * Routes whose service call chain reaches GameAccessService::assertPlayerInGameOrAdmin().
     */
    private const array GUARDED_ROUTES = [
        'app_game_start',
        'app_game_rematch_start',
        'app_game_settings_create',
        'app_game_settings',
        'app_game_settings_read',
        'app_game_finish',
        'app_game_reopen',
        'app_game_finished',
        'app_games_finished',
        'app_game_state',
        'app_game_abort',
        'room_create',
        'room_rematch',
        'app_game_throw',
        'app_game_throw_delta',
        'app_game_throw_undo',
        'app_game_throw_undo_delta',
        'create_invitation',
    ];

    /** @var list<array{string, string}> */
    private const array LOGIN_FAILURES = [['/api/login', '401'], ['/api/login', '429']];

    /** @var array<string, mixed>|null */
    private ?array $document = null;

    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new DevFunctionalKernel('dev', true);
    }

    public function testEveryDocumentedMethodIsAcceptedByItsRoute(): void
    {
        $offences = [];
        foreach ($this->operations() as [$path, $method]) {
            $route = $this->routeFor($path, $method);
            if (null === $route || [] === $route['methods']) {
                $offences[] = strtoupper($method).' '.$path;
            }
        }

        self::assertSame([], $offences, 'Operations that no route with explicit methods accepts.');
    }

    public function testNoResponseDocumentsAStrippedStatusField(): void
    {
        $offences = [];
        foreach ($this->operations() as [$path, $method, $operation]) {
            foreach ($operation['responses'] ?? [] as $code => $response) {
                $schema = $response['content']['application/json']['schema'] ?? [];
                // The subscriber strips only an int status; the health probe's string "ok" stays.
                // A failed login is answered by ApiAuthenticationFailureHandler, which bypasses
                // the subscriber, so its 401 body really carries "status". The GET variant shares the schema.
                if (!in_array([$path, (string) $code], self::LOGIN_FAILURES, true) && 'integer' === ($schema['properties']['status']['type'] ?? null)) {
                    $offences[] = strtoupper($method).' '.$path.' '.$code;
                }
            }
        }

        self::assertSame([], $offences, 'Responses documenting a body "status" that the API moves into the HTTP code.');
    }

    public function testEveryRequestPayloadOperationDocuments422(): void
    {
        $offences = [];
        foreach ($this->operations() as [$path, $method, $operation]) {
            $route = $this->routeFor($path, $method);
            if (null !== $route && $this->bindsRequestPayload($route['controller']) && !isset($operation['responses']['422'])) {
                $offences[] = strtoupper($method).' '.$path;
            }
        }

        self::assertSame([], $offences);
    }

    public function testEveryGuardedOperationDocuments403(): void
    {
        $offences = [];
        $seen = [];
        foreach ($this->operations() as [$path, $method, $operation]) {
            $route = $this->routeFor($path, $method);
            if (null === $route || !in_array($route['name'], self::GUARDED_ROUTES, true)) {
                continue;
            }
            $seen[] = $route['name'];
            if (!isset($operation['responses']['403'])) {
                $offences[] = strtoupper($method).' '.$path;
            }
        }

        self::assertSame([], $offences);
        self::assertEqualsCanonicalizing(self::GUARDED_ROUTES, array_unique($seen), 'Every guarded route is documented.');
    }

    public function testLoginDocuments401AndLogoutItsResponse(): void
    {
        $paths = $this->document()['paths'];

        self::assertArrayHasKey('401', $paths['/api/login']['post']['responses']);
        self::assertArrayHasKey('429', $paths['/api/login']['post']['responses']);
        self::assertArrayHasKey('429', $paths['/api/register']['post']['responses']);
        self::assertArrayHasKey('responses', $paths['/api/logout']['post']);
        self::assertArrayNotHasKey('default', $paths['/api/logout']['post']['responses']);
    }

    public function testRegisterDocumentsTheEightCharacterPasswordMinimum(): void
    {
        $body = $this->document()['paths']['/api/register']['post']['requestBody']['content']['application/json']['schema'];

        self::assertSame(8, $body['properties']['plainPassword']['minLength']);
    }

    public function testRegisterDocumentsTheLoginSuccessBodyAndTheRememberMeCookie(): void
    {
        $created = $this->document()['paths']['/api/register']['post']['responses']['201'];
        $schema = $created['content']['application/json']['schema'];

        self::assertSame(['success', 'roles', 'id', 'email', 'username', 'redirect'], $schema['required']);
        self::assertArrayHasKey('gameId', $schema['properties']);
        self::assertSame(
            ['GAME_ALREADY_STARTED', 'GAME_JOIN_NOT_ALLOWED', 'GAME_ROOM_FULL', 'GAME_NOT_FOUND'],
            $schema['properties']['invitationError']['enum'],
        );
        self::assertArrayNotHasKey('message', $schema['properties']);
        self::assertArrayHasKey('Set-Cookie', $created['headers']);
    }

    public function testFinishedDocuments409AndTheTypeDiscriminatorsAreLiterals(): void
    {
        $document = $this->document();

        foreach (['/api/game/{gameId}/finished', '/api/games/{gameId}/finished'] as $path) {
            self::assertArrayHasKey('409', $document['paths'][$path]['get']['responses'], $path);
        }
        self::assertArrayNotHasKey('409', $document['paths']['/api/game/{gameId}/settings']['get']['responses']);

        $schemas = $document['components']['schemas'];
        self::assertSame(['full-state'], $schemas['GameResponseDto']['properties']['type']['enum']);
        self::assertSame(['ack'], $schemas['ThrowAckDto']['properties']['type']['enum']);
        self::assertSame(['ack'], $schemas['UndoAckDto']['properties']['type']['enum']);
    }

    public function testCurrentGameDocumentsNullableFieldsAnd401And403(): void
    {
        $document = $this->document();

        $responses = $document['paths']['/api/player/current-game']['get']['responses'];
        self::assertArrayHasKey('200', $responses);
        self::assertArrayHasKey('401', $responses);
        self::assertArrayHasKey('403', $responses);
        $examples = $responses['200']['content']['application/json']['examples'];
        self::assertSame(['gameId' => 12, 'status' => 'lobby'], $examples['lobby']['value']);
        self::assertSame(['gameId' => null, 'status' => null], $examples['none']['value']);

        $properties = $document['components']['schemas']['CurrentGameResponseDto']['properties'];
        self::assertSame(['gameId', 'status'], array_keys($properties));
        self::assertTrue($properties['gameId']['nullable'] ?? in_array('null', (array) ($properties['gameId']['type'] ?? []), true));
        self::assertTrue($properties['status']['nullable'] ?? in_array('null', (array) ($properties['status']['type'] ?? []), true));
    }

    public function testStartGameRequestDocumentsOnlyCamelCaseSettingKeys(): void
    {
        $properties = array_keys($this->document()['components']['schemas']['StartGameRequest']['properties']);

        foreach (['startScore', 'doubleOut', 'tripleOut'] as $key) {
            self::assertContains($key, $properties);
        }
        foreach (['startscore', 'doubleout', 'tripleout'] as $legacyKey) {
            self::assertNotContains($legacyKey, $properties);
        }
    }

    public function testGameStartAndRematchStartDocument400ForConflictingSettingKeys(): void
    {
        $paths = $this->document()['paths'];

        foreach (['/api/game/{gameId}/start', '/api/game/{gameId}/rematch/start'] as $path) {
            self::assertArrayHasKey('400', $paths[$path]['post']['responses'], $path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        if (null === $this->document) {
            $client = static::createClient(['environment' => 'dev', 'debug' => true]);
            $client->request('GET', '/api/doc.json');
            self::assertResponseStatusCodeSame(Response::HTTP_OK);
            $this->document = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        }

        return $this->document;
    }

    /**
     * @return list<array{string, string, array<string, mixed>}>
     */
    private function operations(): array
    {
        $operations = [];
        foreach ($this->document()['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (is_array($operation) && isset($operation['responses'])) {
                    $operations[] = [(string) $path, (string) $method, $operation];
                }
            }
        }

        return $operations;
    }

    /**
     * @return array{name: string, methods: list<string>, controller: string}|null
     */
    private function routeFor(string $path, string $method): ?array
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        foreach ($router->getRouteCollection() as $name => $route) {
            if (ltrim($route->getPath(), '/') !== ltrim($path, '/')) {
                continue;
            }
            $methods = array_map('strtolower', $route->getMethods());
            if ([] === $methods || in_array(strtolower($method), $methods, true)) {
                return ['name' => $name, 'methods' => $methods, 'controller' => (string) $route->getDefault('_controller')];
            }
        }

        return null;
    }

    private function bindsRequestPayload(string $controller): bool
    {
        if (!str_contains($controller, '::')) {
            return false;
        }

        [$class, $action] = explode('::', $controller, 2);
        foreach ((new ReflectionMethod($class, $action))->getParameters() as $parameter) {
            if ([] !== $parameter->getAttributes(MapRequestPayload::class)) {
                return true;
            }
        }

        return false;
    }
}
