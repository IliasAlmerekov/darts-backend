<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Controller;

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

    public function testInvalidJsonAnswers400WithAnEnglishMessage(): void
    {
        $this->post('/api/room/create', '{"startScore": ');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, (string) $this->client->getResponse()->getContent());
        self::assertSame(
            ['success' => false, 'error' => 'REQUEST_INVALID_JSON_BODY', 'message' => 'The request body is not valid JSON.'],
            $this->payload(),
        );
    }

    private function post(string $uri, string $body): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request(Request::METHOD_POST, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], $body);
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
