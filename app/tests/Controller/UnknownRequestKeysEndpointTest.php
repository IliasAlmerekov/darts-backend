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
use App\Enum\GameStatus;
use App\Tests\Support\TestUserFactory;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A #[MapRequestPayload] body with a key its DTO does not declare answers 422
 * REQUEST_VALIDATION_FAILED with one UNKNOWN_FIELD violation per key (ADR 0006, #90).
 */
final class UnknownRequestKeysEndpointTest extends WebTestCase
{
    private const string USER_PREFIX = 'unknownkeys';

    private KernelBrowser $client;

    private User $admin;

    private int $gameId;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->admin = TestUserFactory::create($entityManager, self::USER_PREFIX, 'admin', ['ROLE_ADMIN']);
        $game = (new Game())
            ->setDate(new DateTime())
            ->setStartScore(301)
            ->setDoubleOut(false)
            ->setTripleOut(false)
            ->setStatus(GameStatus::Lobby);
        $entityManager->persist($game);
        $entityManager->flush();
        $this->gameId = (int) $game->getGameId();
    }

    /**
     * One endpoint per DTO family: method, path with {id}, a body the DTO accepts.
     *
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function endpoints(): iterable
    {
        yield 'settings (GameSettingsRequest)' => ['PATCH', '/api/game/{id}/settings', ['startScore' => 501]];
        yield 'room create (RoomCreateRequest)' => ['POST', '/api/room/create', ['startScore' => 501]];
        yield 'game start (StartGameRequest)' => ['POST', '/api/game/{id}/start', ['startScore' => 501]];
        yield 'rematch start (StartGameRequest)' => ['POST', '/api/game/{id}/rematch/start', ['startScore' => 501]];
        yield 'throw (ThrowRequest)' => ['POST', '/api/game/{id}/throw', ['playerId' => 1, 'value' => 20]];
        yield 'throw delta (ThrowRequest)' => ['POST', '/api/game/{id}/throw/delta', ['playerId' => 1, 'value' => 20]];
        yield 'player order (UpdatePlayerOrderRequest)' => ['POST', '/api/room/{id}/positions', ['positions' => [['playerId' => 1, 'position' => 0]]]];
        yield 'guest (GuestPlayerRequest)' => ['POST', '/api/room/{id}/guest', ['username' => 'Guesty']];
        yield 'player leave (PlayerIdPayload)' => ['DELETE', '/api/room/{id}', ['playerId' => 1]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('endpoints')]
    public function testAnUnknownKeyIsRejected(string $method, string $path, array $body): void
    {
        $this->send($method, $path, $body + ['bogus' => 1]);

        $this->assertViolations([['field' => 'bogus', 'code' => 'UNKNOWN_FIELD', 'message' => 'This attribute was not expected.']]);
    }

    public function testRegistrationRejectsAnUnknownKey(): void
    {
        $this->client->request(
            Request::METHOD_POST,
            '/api/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'unknown-key@db-n.com', 'username' => 'unknown_key', 'plainPassword' => 'secret123', 'bogus' => 1], JSON_THROW_ON_ERROR),
        );

        $this->assertViolations([['field' => 'bogus', 'code' => 'UNKNOWN_FIELD', 'message' => 'This attribute was not expected.']]);
    }

    public function testTwoUnknownKeysListTwoViolations(): void
    {
        $this->send('POST', '/api/room/create', ['startScore' => 501, 'bogus' => 1, 'other' => 2]);

        $this->assertViolations([
            ['field' => 'bogus', 'code' => 'UNKNOWN_FIELD', 'message' => 'This attribute was not expected.'],
            ['field' => 'other', 'code' => 'UNKNOWN_FIELD', 'message' => 'This attribute was not expected.'],
        ]);
    }

    public function testAnUnknownKeyIsReportedNextToAnInvalidKnownValue(): void
    {
        $this->send('POST', '/api/room/create', ['startScore' => 123, 'bogus' => 1]);

        $this->assertViolations([
            ['field' => 'bogus', 'code' => 'UNKNOWN_FIELD', 'message' => 'This attribute was not expected.'],
            ['field' => 'startScore', 'code' => 'INVALID_CHOICE', 'message' => 'The value you selected is not a valid choice.'],
        ]);
    }

    public function testAnUnknownKeyIsReportedNextToAWrongType(): void
    {
        $this->send('POST', '/api/room/create', ['startScore' => 'abc', 'bogus' => 1]);

        $this->assertViolations([
            ['field' => 'bogus', 'code' => 'UNKNOWN_FIELD', 'message' => 'This attribute was not expected.'],
            ['field' => 'startScore', 'code' => 'INVALID_TYPE', 'message' => 'This value should be of type int|null.'],
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $path, array $body): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request($method, str_replace('{id}', (string) $this->gameId, $path), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<array{field: string, code: string, message: string}> $violations
     */
    private function assertViolations(array $violations): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, (string) $this->client->getResponse()->getContent());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('REQUEST_VALIDATION_FAILED', $payload['error'] ?? null);
        $actual = $payload['violations'] ?? null;
        self::assertIsArray($actual);
        usort($actual, static fn (array $a, array $b): int => strcmp($a['field'], $b['field']));
        self::assertSame($violations, $actual);
    }
}
