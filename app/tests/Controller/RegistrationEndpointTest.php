<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\Invitation;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\UserRepository;
use App\Tests\Support\TestUserFactory;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Posts to /api/register through the real kernel, form, and validator, so a constraint
 * that throws while the form is built shows up as a failing status code.
 *
 * A successful registration logs the player in and answers like a login would (#81).
 */
final class RegistrationEndpointTest extends WebTestCase
{
    private const string USER_PREFIX = 'reg';
    private const string REMEMBER_COOKIE = 'REMEMBERME';

    public function testValidPayloadRegistersPlayer(): void
    {
        $client = static::createClient();
        $body = $this->validBody();

        $payload = $this->postRegistration($client, $body, Response::HTTP_CREATED);

        self::assertTrue($payload['success']);
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $body['email']]);
        self::assertInstanceOf(User::class, $user);
        self::assertContains('ROLE_PLAYER', $user->getRoles());
    }

    public function testRegistrationLogsTheNewPlayerIn(): void
    {
        $client = static::createClient();
        $body = $this->validBody();

        $this->postRegistration($client, $body, Response::HTTP_CREATED);

        $client->request(Request::METHOD_GET, '/api/login/success');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success'], (string) $client->getResponse()->getContent());
        self::assertSame($body['email'], $payload['email']);
    }

    public function testRegistrationStartsARememberedLogin(): void
    {
        $client = static::createClient();
        $body = $this->validBody();

        $this->postRegistration($client, $body, Response::HTTP_CREATED);

        self::assertNotNull($client->getCookieJar()->get(self::REMEMBER_COOKIE));
        $tokenCount = static::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM rememberme_token WHERE username = :username',
            ['username' => $body['email']],
        );
        self::assertSame(1, (int) $tokenCount);
    }

    public function testRegistrationWithoutInvitationAnswersLikeAPlayerLogin(): void
    {
        $client = static::createClient();
        $body = $this->validBody();

        $payload = $this->postRegistration($client, $body, Response::HTTP_CREATED);

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $body['email']]);
        self::assertInstanceOf(User::class, $user);
        self::assertTrue($payload['success']);
        self::assertSame($user->getId(), $payload['id']);
        self::assertSame($body['email'], $payload['email']);
        self::assertSame($body['username'], $payload['username']);
        self::assertSame(['ROLE_PLAYER'], $payload['roles']);
        self::assertSame('/joined', parse_url($payload['redirect'], PHP_URL_PATH));
        self::assertArrayNotHasKey('gameId', $payload);
        self::assertArrayNotHasKey('invitationError', $payload);
    }

    public function testRegistrationWithInvitationJoinsTheRoom(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $game = $this->createGame($entityManager, GameStatus::Lobby);
        $gameId = (int) $game->getGameId();
        $this->scanInvitation($client, $this->createInvitation($entityManager, $game));
        $body = $this->validBody();

        $payload = $this->postRegistration($client, $body, Response::HTTP_CREATED);

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $body['email']]);
        self::assertInstanceOf(User::class, $user);
        self::assertTrue(static::getContainer()->get(GamePlayersRepositoryInterface::class)->isPlayerInGame($gameId, (int) $user->getId()));
        self::assertSame($gameId, $payload['gameId']);
        self::assertSame($user->getId(), $payload['id']);
        self::assertSame('/joined', parse_url($payload['redirect'], PHP_URL_PATH));
        self::assertArrayNotHasKey('invitationError', $payload);
        $this->assertInvitationCleared($client);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unjoinableInvitations(): iterable
    {
        yield 'game started' => ['GAME_ALREADY_STARTED'];
        yield 'join not allowed' => ['GAME_JOIN_NOT_ALLOWED'];
        yield 'room full' => ['GAME_ROOM_FULL'];
        yield 'game gone' => ['GAME_NOT_FOUND'];
    }

    #[DataProvider('unjoinableInvitations')]
    public function testRegistrationWithUnjoinableInvitationStillLogsThePlayerIn(string $errorCode): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $game = $this->createGame($entityManager, GameStatus::Lobby);
        $this->scanInvitation($client, $this->createInvitation($entityManager, $game));
        $this->makeUnjoinable($entityManager, $game, $errorCode);
        $body = $this->validBody();

        $payload = $this->postRegistration($client, $body, Response::HTTP_CREATED);

        self::assertInstanceOf(User::class, static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $body['email']]));
        self::assertTrue($payload['success']);
        self::assertSame($errorCode, $payload['invitationError']);
        self::assertSame('/joined', parse_url($payload['redirect'], PHP_URL_PATH));
        self::assertArrayNotHasKey('gameId', $payload);
        $this->assertInvitationCleared($client);
        $this->assertLoggedInAs($client, $body['email']);
    }

    public function testInvalidRegistrationKeepsTheInvitationAndLogsNobodyIn(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $game = $this->createGame($entityManager, GameStatus::Lobby);
        $invitation = $this->createInvitation($entityManager, $game);
        $this->scanInvitation($client, $invitation);
        $body = ['plainPassword' => '12345'] + $this->validBody();

        $this->postRegistration($client, $body, Response::HTTP_BAD_REQUEST);

        self::assertNull(static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $body['email']]));
        self::assertNull($client->getCookieJar()->get(self::REMEMBER_COOKIE));
        self::assertSame((string) $invitation->getUuid(), $client->getRequest()->getSession()->get('invitation_uuid'));
        self::assertSame($game->getGameId(), $client->getRequest()->getSession()->get('game_id'));
        $client->request(Request::METHOD_GET, '/api/login/success');
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['success']);
    }

    public function testBlankPayloadReturnsFieldErrors(): void
    {
        $client = static::createClient();

        $payload = $this->postRegistration($client, ['email' => '', 'username' => '', 'plainPassword' => ''], Response::HTTP_BAD_REQUEST);

        self::assertFalse($payload['success']);
        self::assertSame(['Please enter an email address'], $payload['errors']['email'] ?? null);
        self::assertSame(['Please enter a username'], $payload['errors']['username'] ?? null);
        self::assertSame(['Please enter a password'], $payload['errors']['plainPassword'] ?? null);
    }

    public function testInvalidEmailReturnsFieldError(): void
    {
        $client = static::createClient();

        $payload = $this->postRegistration($client, ['email' => 'not-an-email'] + $this->validBody(), Response::HTTP_BAD_REQUEST);

        self::assertSame(['Please enter a valid email address'], $payload['errors']['email'] ?? null);
    }

    public function testShortPasswordReturnsFieldError(): void
    {
        $client = static::createClient();

        $payload = $this->postRegistration($client, ['plainPassword' => '12345'] + $this->validBody(), Response::HTTP_BAD_REQUEST);

        self::assertSame(['Your password should be at least 6 characters long.'], $payload['errors']['plainPassword'] ?? null);
    }

    private function createGame(EntityManagerInterface $entityManager, GameStatus $status): Game
    {
        $game = (new Game())
            ->setDate(new DateTime())
            ->setStartScore(301)
            ->setDoubleOut(false)
            ->setTripleOut(false)
            ->setStatus($status);
        $entityManager->persist($game);
        $entityManager->flush();

        return $game;
    }

    private function createInvitation(EntityManagerInterface $entityManager, Game $game): Invitation
    {
        $invitation = (new Invitation())->setUuid(Uuid::v4())->setGameId((int) $game->getGameId());
        $entityManager->persist($invitation);
        $entityManager->flush();

        return $invitation;
    }

    private function scanInvitation(KernelBrowser $client, Invitation $invitation): void
    {
        $client->request(Request::METHOD_GET, sprintf('/api/invite/join/%s', $invitation->getUuid()));
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
    }

    private function makeUnjoinable(EntityManagerInterface $entityManager, Game $game, string $errorCode): void
    {
        match ($errorCode) {
            'GAME_ALREADY_STARTED' => $game->setStatus(GameStatus::Started),
            'GAME_JOIN_NOT_ALLOWED' => $game->setStatus(GameStatus::Aborted),
            'GAME_ROOM_FULL' => $this->fillRoom($entityManager, $game),
            'GAME_NOT_FOUND' => $entityManager->remove($game),
        };
        $entityManager->flush();
    }

    private function fillRoom(EntityManagerInterface $entityManager, Game $game): void
    {
        for ($position = 1; $position <= 10; ++$position) {
            $member = TestUserFactory::create($entityManager, self::USER_PREFIX, 'm'.$position, ['ROLE_PLAYER']);
            $entityManager->persist(
                (new GamePlayers())
                    ->setGame($game)
                    ->setPlayer($member)
                    ->setPosition($position)
                    ->setDisplayNameSnapshot((string) $member->getUsername())
            );
        }
    }

    private function assertInvitationCleared(KernelBrowser $client): void
    {
        self::assertNull($client->getRequest()->getSession()->get('invitation_uuid'));
        self::assertNull($client->getRequest()->getSession()->get('game_id'));
    }

    private function assertLoggedInAs(KernelBrowser $client, string $email): void
    {
        $client->request(Request::METHOD_GET, '/api/login/success');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($email, $payload['email'] ?? null, (string) $client->getResponse()->getContent());
        self::assertArrayNotHasKey('invitationError', $payload);
    }

    /**
     * @return array{email: string, username: string, plainPassword: string}
     */
    private function validBody(): array
    {
        $suffix = bin2hex(random_bytes(4));

        return [
            'email' => sprintf('register-%s@test.dev', $suffix),
            'username' => sprintf('register_%s', $suffix),
            'plainPassword' => 'secret123',
        ];
    }

    /**
     * Asserts the status before decoding, so a 500 error page fails on the status, not on the JSON.
     *
     * @param array<string, string> $body
     *
     * @return array<array-key, mixed>
     */
    private function postRegistration(KernelBrowser $client, array $body, int $expectedStatus): array
    {
        $client->request(Request::METHOD_POST, '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame($expectedStatus, (string) $client->getResponse()->getContent());

        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
