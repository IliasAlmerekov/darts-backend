<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\User;
use App\Tests\Support\TestUserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A #[MapRequestPayload] body that fails validation answers 422 REQUEST_VALIDATION_FAILED
 * with one violation per failed constraint (ADR 0006).
 */
final class RequestValidationErrorEndpointTest extends WebTestCase
{
    private const string USER_PREFIX = 'reqvalidation';

    private KernelBrowser $client;

    private User $admin;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->admin = TestUserFactory::create($entityManager, self::USER_PREFIX, 'admin', ['ROLE_ADMIN']);
        $entityManager->flush();
    }

    public function testInvalidStartScoreListsItsViolation(): void
    {
        $this->post('/api/room/create', '{"startScore": 123}');

        $this->assertValidationFailed([
            ['field' => 'startScore', 'code' => 'INVALID_CHOICE', 'message' => 'The value you selected is not a valid choice.'],
        ]);
    }

    public function testTwoInvalidFieldsListTwoViolations(): void
    {
        $this->post('/api/room/create', '{"startScore": 123, "playerIds": [7, 7]}');

        $this->assertValidationFailed([
            ['field' => 'playerIds', 'code' => 'DUPLICATE', 'message' => 'This collection should contain only unique elements.'],
            ['field' => 'startScore', 'code' => 'INVALID_CHOICE', 'message' => 'The value you selected is not a valid choice.'],
        ]);
    }

    /**
     * @return iterable<string, array{string, string, list<array{field: string, code: string, message: string}>}>
     */
    public static function nestedAndTypeFailures(): iterable
    {
        yield 'All reports the inner constraint' => ['/api/room/create', '{"playerIds": [-1]}', [
            ['field' => 'playerIds[0]', 'code' => 'OUT_OF_RANGE', 'message' => 'This value should be positive.'],
        ]];
        yield 'a value the serializer cannot map' => ['/api/room/create', '{"startScore": "abc"}', [
            ['field' => 'startScore', 'code' => 'INVALID_TYPE', 'message' => 'This value should be of type int|null.'],
        ]];
        yield 'a field Collection misses' => ['/api/room/1/positions', '{"positions": [{"playerId": 3}]}', [
            ['field' => 'positions[0][position]', 'code' => 'REQUIRED', 'message' => 'This field is missing.'],
        ]];
    }

    /**
     * @param list<array{field: string, code: string, message: string}> $violations
     */
    #[DataProvider('nestedAndTypeFailures')]
    public function testNestedAndTypeFailuresUseTheTable(string $uri, string $body, array $violations): void
    {
        $this->post($uri, $body);

        $this->assertValidationFailed($violations);
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function listsOverTheSeatLimit(): iterable
    {
        $ids = range(1, Game::MAX_PARTICIPANTS + 1);

        yield 'playerIds' => ['/api/room/create', 'playerIds', ['playerIds' => $ids]];
        yield 'excludePlayerIds' => ['/api/room/create', 'excludePlayerIds', ['excludePlayerIds' => $ids]];
        yield 'positions' => ['/api/room/1/positions', 'positions', ['positions' => array_map(
            static fn (int $id): array => ['playerId' => $id, 'position' => $id],
            $ids,
        )]];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('listsOverTheSeatLimit')]
    public function testAListOverTheSeatLimitIsOutOfRange(string $uri, string $field, array $payload): void
    {
        $this->post($uri, json_encode($payload, JSON_THROW_ON_ERROR));

        $this->assertValidationFailed([
            ['field' => $field, 'code' => 'OUT_OF_RANGE', 'message' => 'This collection should contain 10 elements or less.'],
        ]);
    }

    public function testInvalidJsonAnswers400WithAnEnglishMessage(): void
    {
        $this->post('/api/room/create', '{"startScore": ');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, (string) $this->client->getResponse()->getContent());
        self::assertSame(
            ['success' => false, 'error' => 'REQUEST_INVALID_JSON_BODY', 'message' => 'The request body is not valid JSON.'],
            $this->payload(),
        );
    }

    public function testInvalidXmlKeepsTheGenericBadRequestBody(): void
    {
        $this->post('/api/room/create', '<request><startScore>', 'application/xml');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, (string) $this->client->getResponse()->getContent());
        self::assertSame(
            ['success' => false, 'error' => 'BAD_REQUEST', 'message' => 'Bad Request'],
            $this->payload(),
        );
    }

    /**
     * @return iterable<string, array{string, string, string, int, array{success: false, error: string, message: string}}>
     */
    public static function genericHttpErrors(): iterable
    {
        yield 'unknown route' => [Request::METHOD_GET, '/api/nope', 'application/json', Response::HTTP_NOT_FOUND, ['success' => false, 'error' => 'NOT_FOUND', 'message' => 'Not Found']];
        yield 'wrong method' => [Request::METHOD_DELETE, '/api/register', 'application/json', Response::HTTP_METHOD_NOT_ALLOWED, ['success' => false, 'error' => 'METHOD_NOT_ALLOWED', 'message' => 'Method Not Allowed']];
        yield 'non-JSON registration' => [Request::METHOD_POST, '/api/register', 'text/plain', Response::HTTP_UNSUPPORTED_MEDIA_TYPE, ['success' => false, 'error' => 'UNSUPPORTED_MEDIA_TYPE', 'message' => 'Unsupported Media Type']];
    }

    /**
     * @param array{success: false, error: string, message: string} $body
     */
    #[DataProvider('genericHttpErrors')]
    public function testGenericHttpErrorsTakeTheirCodeFromTheStatus(string $method, string $uri, string $contentType, int $status, array $body): void
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => $contentType], '{}');

        self::assertResponseStatusCodeSame($status, (string) $this->client->getResponse()->getContent());
        self::assertSame($body, $this->payload());
    }

    private function post(string $uri, string $body, string $contentType = 'application/json'): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request(Request::METHOD_POST, $uri, [], [], ['CONTENT_TYPE' => $contentType], $body);
    }

    /**
     * @param list<array{field: string, code: string, message: string}> $violations
     */
    private function assertValidationFailed(array $violations): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, (string) $this->client->getResponse()->getContent());
        self::assertSame(
            [
                'success' => false,
                'error' => 'REQUEST_VALIDATION_FAILED',
                'message' => 'The request body failed validation.',
                'violations' => $violations,
            ],
            $this->payload(),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function payload(): array
    {
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
