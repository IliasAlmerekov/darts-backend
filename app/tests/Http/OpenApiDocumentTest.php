<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Http;

use App\Dto\PlayerProfileDoubleDto;
use App\Dto\PlayerProfileSegmentHitsDto;
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
        'app_game_settings',
        'app_game_settings_read',
        'app_game_finish',
        'app_game_reopen',
        'app_game_finished',
        'app_game_state',
        'app_game_abort',
        'room_rematch',
        'app_game_throw_delta',
        'app_game_throw_undo_delta',
        'create_invitation',
    ];

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

    public function testRematchAndInvitationDocumentTheSharedNotFoundBody(): void
    {
        $paths = $this->document()['paths'];

        foreach ([$paths['/api/room/{id}/rematch']['post'], $paths['/api/invite/create/{id}']['post']] as $operation) {
            self::assertSame('#/components/schemas/ApiError', $operation['responses']['404']['content']['application/json']['schema']['$ref'] ?? null);
        }
    }

    /**
     * Most payloads fail validation with 422; registration keeps its 400 (#52).
     */
    public function testEveryRequestPayloadOperationDocumentsItsValidationStatus(): void
    {
        $offences = [];
        foreach ($this->operations() as [$path, $method, $operation]) {
            $route = $this->routeFor($path, $method);
            $status = null !== $route ? $this->payloadValidationStatus($route['controller']) : null;
            if (null !== $status && !isset($operation['responses'][(string) $status])) {
                $offences[] = sprintf('%s %s (%d)', strtoupper($method), $path, $status);
            }
        }

        self::assertSame([], $offences);
    }

    /**
     * A payload operation answers a body that is not valid JSON with 400 REQUEST_INVALID_JSON_BODY,
     * and its 400 documents the error body.
     */
    public function testEveryRequestPayloadOperationDocumentsTheInvalidJsonCode(): void
    {
        $offences = [];
        foreach ($this->operations() as [$path, $method, $operation]) {
            $route = $this->routeFor($path, $method);
            if (null === $route || null === $this->payloadValidationStatus($route['controller'])) {
                continue;
            }
            $description = $operation['responses']['400']['description'] ?? '';
            $schema = $operation['responses']['400']['content']['application/json']['schema'] ?? [];
            if (!is_string($description) || !str_contains($description, 'REQUEST_INVALID_JSON_BODY') || [] === $schema) {
                $offences[] = strtoupper($method).' '.$path;
            }
        }

        self::assertSame([], $offences);
    }

    /**
     * ADR 0006: the 422 body is documented once and every payload operation references it.
     */
    public function testEveryRequestPayloadOperationReferencesTheValidationBody(): void
    {
        $ref = '#/components/schemas/RequestValidationFailed';
        $offences = [];
        $seen = 0;
        foreach ($this->operations() as [$path, $method, $operation]) {
            $route = $this->routeFor($path, $method);
            if (null === $route || Response::HTTP_UNPROCESSABLE_ENTITY !== $this->payloadValidationStatus($route['controller'])) {
                continue;
            }
            ++$seen;
            $schema = $operation['responses']['422']['content']['application/json']['schema'] ?? [];
            $refs = [$schema['$ref'] ?? null, ...array_column($schema['anyOf'] ?? [], '$ref')];
            if (!in_array($ref, $refs, true)) {
                $offences[] = strtoupper($method).' '.$path;
            }
        }

        self::assertSame([], $offences);
        self::assertGreaterThan(0, $seen);

        $body = $this->document()['components']['schemas']['RequestValidationFailed'];
        self::assertSame(['success', 'error', 'message', 'violations'], $body['required']);
        self::assertSame(['REQUEST_VALIDATION_FAILED'], $body['properties']['error']['enum']);
        self::assertSame(['field', 'code', 'message'], $body['properties']['violations']['items']['required']);
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

        foreach ([['post', '401'], ['post', '429'], ['get', '401']] as [$method, $code]) {
            self::assertSame(
                '#/components/schemas/ApiError',
                $paths['/api/login'][$method]['responses'][$code]['content']['application/json']['schema']['$ref'] ?? null,
                strtoupper($method).' /api/login '.$code,
            );
        }
        self::assertStringNotContainsString('last_username', (string) json_encode($paths['/api/login']['post']['responses']['401']));
        self::assertArrayHasKey('429', $paths['/api/register']['post']['responses']);
        self::assertArrayHasKey('responses', $paths['/api/logout']['post']);
        self::assertArrayNotHasKey('default', $paths['/api/logout']['post']['responses']);
        self::assertSame([204], array_keys($paths['/api/logout']['post']['responses']));
        self::assertArrayNotHasKey('content', $paths['/api/logout']['post']['responses']['204']);
        self::assertSame(
            '#/components/schemas/ApiError',
            $paths['/api/login/success']['get']['responses']['401']['content']['application/json']['schema']['$ref'] ?? null,
        );
        self::assertArrayHasKey('suggestions', $paths['/api/room/{id}/guest']['post']['responses']['409']['content']['application/json']['schema']['properties']);
    }

    public function testCsrfEndpointIsGoneAndTheCrossOriginCodeIsDocumented(): void
    {
        $document = $this->document();

        self::assertArrayNotHasKey('/api/csrf', $document['paths']);
        self::assertStringContainsString('`CROSS_ORIGIN_REQUEST`', (string) $document['components']['schemas']['ApiError']['properties']['error']['description']);
        $loginSchema = $document['paths']['/api/login']['post']['requestBody']['content']['application/x-www-form-urlencoded']['schema'];
        self::assertSame('csrf-token', $loginSchema['properties']['_csrf_token']['enum'][0] ?? null);
        self::assertSame(['_username', '_password', '_csrf_token'], $loginSchema['required'] ?? null);
    }

    public function testEveryForbiddenResponseNamesAccessDeniedAndUsesTheErrorSchema(): void
    {
        $checked = 0;
        foreach ($this->document()['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $forbidden = $operation['responses']['403'] ?? null;
                if (null === $forbidden) {
                    continue;
                }
                ++$checked;
                $label = strtoupper((string) $method).' '.$path;
                self::assertStringContainsString('`ACCESS_DENIED`', (string) $forbidden['description'], $label);
                self::assertSame('#/components/schemas/ApiError', $forbidden['content']['application/json']['schema']['$ref'] ?? null, $label);
            }
        }
        self::assertGreaterThan(0, $checked);
    }

    public function testRegisterDocumentsTheRequestDtoAndTheSixCharacterPasswordMinimum(): void
    {
        $document = $this->document();
        $ref = $document['paths']['/api/register']['post']['requestBody']['content']['application/json']['schema']['$ref'];

        self::assertSame('#/components/schemas/RegistrationRequest', $ref);
        $body = $document['components']['schemas']['RegistrationRequest'];
        self::assertEqualsCanonicalizing(['email', 'username', 'plainPassword'], $body['required']);
        self::assertSame(6, $body['properties']['plainPassword']['minLength']);
    }

    public function testRegisterDocumentsTheCompanyEmailDomain(): void
    {
        $document = $this->document();
        $email = $document['components']['schemas']['RegistrationRequest']['properties']['email'];
        $responses = $document['paths']['/api/register']['post']['responses'];

        self::assertStringContainsString('`db-n.com`', $email['description']);
        self::assertSame('alice@db-n.com', $email['example']);
        self::assertStringContainsString('EMAIL_DOMAIN_NOT_ALLOWED', $responses['422']['description']);
        self::assertStringContainsString('EMAIL_DOMAIN_NOT_ALLOWED', $email['description']);
        self::assertSame('alice@db-n.com', $responses['201']['content']['application/json']['schema']['properties']['email']['example']);
    }

    public function testRegisterDocumentsErrorsInTheSharedBody(): void
    {
        $responses = $this->document()['paths']['/api/register']['post']['responses'];

        self::assertSame('#/components/schemas/RequestValidationFailed', $responses['422']['content']['application/json']['schema']['$ref']);
        self::assertSame('#/components/schemas/ApiError', $responses['400']['content']['application/json']['schema']['$ref']);
        self::assertStringContainsString('REQUEST_INVALID_JSON_BODY', $responses['400']['description']);
        foreach (['409', '429'] as $status) {
            $schema = $responses[$status]['content']['application/json']['schema'];
            self::assertSame(['success', 'error', 'message'], $schema['required']);
            self::assertDoesNotMatchRegularExpression('/[äöüß]/u', $schema['properties']['message']['example']);
        }
    }

    public function testRegisterDocumentsTheUnsupportedMediaType(): void
    {
        self::assertArrayHasKey('415', $this->document()['paths']['/api/register']['post']['responses']);
    }

    public function testRegisterDocumentsTheAccountExistsConflict(): void
    {
        $conflict = $this->document()['paths']['/api/register']['post']['responses']['409'];
        $schema = $conflict['content']['application/json']['schema'];

        self::assertSame(['success', 'error', 'message'], $schema['required']);
        self::assertSame('REGISTRATION_ACCOUNT_EXISTS', $schema['properties']['error']['example']);
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

        self::assertArrayHasKey('409', $document['paths']['/api/game/{gameId}/finished']['get']['responses']);
        self::assertArrayNotHasKey('409', $document['paths']['/api/game/{gameId}/settings']['get']['responses']);

        $schemas = $document['components']['schemas'];
        self::assertSame(['full-state'], $schemas['GameResponseDto']['properties']['type']['enum']);
        self::assertSame(['ack'], $schemas['ThrowAckDto']['properties']['type']['enum']);
        self::assertSame(['ack'], $schemas['UndoAckDto']['properties']['type']['enum']);
    }

    public function testRemovedRoutesHaveNoOperation(): void
    {
        $paths = $this->document()['paths'];
        $removed = [
            ['/api/game/settings', 'post'],
            ['/api/game/{gameId}/rematch/start', 'post'],
            ['/api/games/{gameId}', 'get'],
            ['/api/games/{gameId}/finished', 'get'],
        ];

        foreach ($removed as [$path, $method]) {
            self::assertArrayNotHasKey($method, $paths[$path] ?? [], strtoupper($method).' '.$path);
        }
    }

    public function testFinishAbortAndUndoDocumentTheirStatusConflicts(): void
    {
        $paths = $this->document()['paths'];
        $expected = [
            ['/api/game/{gameId}/finish', 'post', 'GAME_FINISH_NOT_ALLOWED'],
            ['/api/game/{gameId}/abort', 'patch', 'GAME_ABORT_NOT_ALLOWED'],
            ['/api/game/{gameId}/throw/delta', 'delete', 'GAME_THROW_NOT_ALLOWED'],
        ];

        foreach ($expected as [$path, $method, $errorCode]) {
            $conflict = $paths[$path][$method]['responses']['409'] ?? null;
            self::assertIsArray($conflict, $path);
            self::assertStringContainsString($errorCode, $conflict['description'], $path);
        }
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

        $schema = $document['components']['schemas']['CurrentGameResponseDto'];
        self::assertSame(['gameId', 'status'], $schema['required'] ?? null);
        $properties = $schema['properties'];
        self::assertSame(['gameId', 'status'], array_keys($properties));
        self::assertSame('integer', $properties['gameId']['type']);
        self::assertTrue($properties['gameId']['nullable'] ?? in_array('null', (array) ($properties['gameId']['type'] ?? []), true));
        self::assertSame('string', $properties['status']['type']);
        self::assertTrue($properties['status']['nullable'] ?? in_array('null', (array) ($properties['status']['type'] ?? []), true));
        self::assertSame(['lobby', 'started', null], $properties['status']['enum'] ?? null);
    }

    public function testProfileDocumentsResponseSchemaAnd401And403(): void
    {
        $document = $this->document();

        $operation = $document['paths']['/api/player/profile']['get'];
        self::assertSame(['Player'], $operation['tags']);
        foreach (['200', '401', '403'] as $status) {
            self::assertArrayHasKey($status, $operation['responses'], $status);
        }
        self::assertSame(
            '#/components/schemas/PlayerProfileResponseDto',
            $operation['responses']['200']['content']['application/json']['schema']['$ref']
        );

        $schemas = $document['components']['schemas'];
        self::assertSame(['stats', 'recentGames', 'registeredAt'], array_keys($schemas['PlayerProfileResponseDto']['properties']));
        $registeredAt = $schemas['PlayerProfileResponseDto']['properties']['registeredAt'];
        self::assertSame('string', $registeredAt['type']);
        self::assertTrue($registeredAt['nullable'] ?? in_array('null', (array) ($registeredAt['type'] ?? []), true));
        $stats = $schemas['PlayerProfileStatsDto']['properties'];
        self::assertSame(
            [
                'gamesPlayed', 'scoreAverage', 'wins', 'winRate', 'checkoutRate', 'checkoutHits', 'checkoutAttempts',
                'hitDistribution', 'mostHitSegment', 'favoriteDoubles', 'highestFinish',
            ],
            array_keys($stats)
        );
        self::assertSame('#/components/schemas/PlayerProfileSegmentHitsDto', $stats['hitDistribution']['items']['$ref']);
        self::assertSame('#/components/schemas/PlayerProfileMostHitSegmentDto', $stats['mostHitSegment']['oneOf'][0]['$ref']);
        self::assertTrue($stats['mostHitSegment']['nullable']);
        self::assertSame('#/components/schemas/PlayerProfileDoubleDto', $stats['favoriteDoubles']['items']['$ref']);
        self::assertSame('#/components/schemas/PlayerProfileFinishDto', $stats['highestFinish']['oneOf'][0]['$ref']);
        self::assertTrue($stats['highestFinish']['nullable']);
        self::assertSame(['segment', 'hits'], array_keys($schemas['PlayerProfileSegmentHitsDto']['properties']));
        self::assertSame(['segment', 'hits', 'share'], array_keys($schemas['PlayerProfileMostHitSegmentDto']['properties']));
        self::assertSame(['double', 'hits', 'attempts', 'hitRate'], array_keys($schemas['PlayerProfileDoubleDto']['properties']));
        self::assertSame(['score', 'darts', 'throws'], array_keys($schemas['PlayerProfileFinishDto']['properties']));
        self::assertSame(
            PlayerProfileSegmentHitsDto::SEGMENTS,
            $schemas['PlayerProfileSegmentHitsDto']['properties']['segment']['enum']
        );
        self::assertSame(
            PlayerProfileSegmentHitsDto::SEGMENTS,
            $schemas['PlayerProfileMostHitSegmentDto']['properties']['segment']['enum']
        );
        self::assertSame(PlayerProfileDoubleDto::DOUBLES, $schemas['PlayerProfileDoubleDto']['properties']['double']['enum']);
        foreach (['wins', 'winRate', 'checkoutRate', 'checkoutHits', 'checkoutAttempts', 'hitDistribution', 'mostHitSegment', 'favoriteDoubles', 'highestFinish'] as $field) {
            self::assertNotEmpty($stats[$field]['description'] ?? null, $field.' has no description');
        }
        self::assertSame(
            ['gameId', 'finishedAt', 'placement', 'roundAverage', 'participantsCount', 'participants'],
            array_keys($schemas['PlayerProfileGameDto']['properties'])
        );
        self::assertSame(
            '#/components/schemas/PlayerProfileParticipantDto',
            $schemas['PlayerProfileGameDto']['properties']['participants']['items']['$ref']
        );
        self::assertSame(
            ['playerId', 'displayName', 'placement', 'isSelf'],
            array_keys($schemas['PlayerProfileParticipantDto']['properties'])
        );
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

    public function testGameStartDocumentsNoConflictingSettingKeys(): void
    {
        $description = $this->document()['paths']['/api/game/{gameId}/start']['post']['responses']['400']['description'] ?? '';

        self::assertStringNotContainsString('REQUEST_CONFLICTING_SETTING_KEYS', $description);
        self::assertStringContainsString('REQUEST_INVALID_JSON_BODY', $description);
    }

    public function testRequestValidationFailedSchemaDocumentsUnknownField(): void
    {
        $schema = $this->document()['components']['schemas']['RequestValidationFailed'];

        self::assertStringContainsString('UNKNOWN_FIELD', $schema['properties']['violations']['items']['properties']['code']['description']);
    }

    public function testGameStartDocumentsTheGameStateAsItsSuccessBody(): void
    {
        $responses = $this->document()['paths']['/api/game/{gameId}/start']['post']['responses'];

        self::assertSame('#/components/schemas/GameResponseDto', $responses['200']['content']['application/json']['schema']['$ref'] ?? null);
    }

    public function testRoomCreateAndRematchDocumentTheOpenLobbyReuse(): void
    {
        $paths = $this->document()['paths'];

        foreach (['/api/room/create', '/api/room/{id}/rematch'] as $path) {
            self::assertStringContainsString('höchstens ein Spiel in der Lobby', $paths[$path]['post']['description'] ?? '', $path);
        }
    }

    public function testRoomCreateDocuments404ForAnUnknownPlayer(): void
    {
        $responses = $this->document()['paths']['/api/room/create']['post']['responses'];

        self::assertStringContainsString('PLAYER_NOT_FOUND', $responses['404']['description'] ?? '');
    }

    public function testRoomCreateDocumentsPreviousGameIdAsDeprecated(): void
    {
        $previousGameId = $this->document()['components']['schemas']['RoomCreateRequest']['properties']['previousGameId'];

        self::assertTrue($previousGameId['deprecated'] ?? false);
        self::assertStringContainsString('ignoriert', $previousGameId['description'] ?? '');
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

    /**
     * Returns the status a #[MapRequestPayload] argument fails validation with, or null
     * when the action binds no request payload.
     */
    private function payloadValidationStatus(string $controller): ?int
    {
        if (!str_contains($controller, '::')) {
            return null;
        }

        [$class, $action] = explode('::', $controller, 2);
        foreach ((new ReflectionMethod($class, $action))->getParameters() as $parameter) {
            foreach ($parameter->getAttributes(MapRequestPayload::class) as $attribute) {
                return $attribute->newInstance()->validationFailedStatusCode;
            }
        }

        return null;
    }
}
