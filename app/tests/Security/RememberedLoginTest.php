<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\SameOriginLogin;
use App\Entity\User;
use App\Tests\Support\TestUserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A player stays logged in for 7 days after the last visit through a remember-me cookie
 * (docs/adr/0002-player-remembered-login.md). The admin account on the shared tablet
 * never gets one.
 */
final class RememberedLoginTest extends WebTestCase
{
    private const string USER_PREFIX = 'remembered';
    private const string LOGIN_PASSWORD = 'remembered-secret';
    private const string REMEMBER_COOKIE = 'REMEMBERME';
    private const int SEVEN_DAYS = 604800;

    public function testPlayerStaysAuthenticatedAfterLosingTheSession(): void
    {
        $client = static::createClient();
        $player = $this->createUser('player', ['ROLE_PLAYER']);

        $this->logIn($client, $player);
        $this->dropSessionCookie($client);

        $client->request(Request::METHOD_GET, '/api/login/success');

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($player->getId(), $payload['id']);
    }

    public function testVisitWithinSevenDaysReissuesTheCookieWithAFreshLifetime(): void
    {
        $client = static::createClient();
        $player = $this->createUser('reissue', ['ROLE_PLAYER']);

        $this->logIn($client, $player);
        $this->dropSessionCookie($client);
        $this->backdateTokens(self::SEVEN_DAYS / 2);

        $client->request(Request::METHOD_GET, '/api/login/success');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $cookie = $this->rememberCookie($client);
        self::assertNotNull($cookie);
        self::assertEqualsWithDelta(time() + self::SEVEN_DAYS, $cookie->getExpiresTime(), 60);
    }

    public function testPlayerWhoseLastVisitWasOverSevenDaysAgoIsNotAuthenticated(): void
    {
        $client = static::createClient();
        $player = $this->createUser('expired', ['ROLE_PLAYER']);

        $this->logIn($client, $player);
        $this->dropSessionCookie($client);
        $this->backdateTokens(self::SEVEN_DAYS + 3600);

        $client->request(Request::METHOD_GET, '/api/login/success');

        self::assertFalse($this->isAuthenticated($client));
    }

    public function testParallelRequestsWithTheSameCookieStayAuthenticated(): void
    {
        $client = static::createClient();
        $player = $this->createUser('parallel', ['ROLE_PLAYER']);

        $this->logIn($client, $player);
        $this->dropSessionCookie($client);
        $this->backdateTokens(self::SEVEN_DAYS / 2);
        $staleCookie = $this->rememberCookie($client);
        self::assertNotNull($staleCookie);

        // The first request rotates the token. A second request sent before the browser saw
        // the new cookie still presents the old value and must not be treated as theft.
        $client->request(Request::METHOD_GET, '/api/login/success');
        self::assertTrue($this->isAuthenticated($client));

        $this->dropSessionCookie($client);
        $client->getCookieJar()->set($staleCookie);
        $client->request(Request::METHOD_GET, '/api/login/success');
        self::assertTrue($this->isAuthenticated($client));
    }

    public function testRememberedPlayerReachesOnlyWhatASessionPlayerReaches(): void
    {
        $client = static::createClient();
        $player = $this->createUser('matrix', ['ROLE_PLAYER']);

        $this->logIn($client, $player);
        $this->dropSessionCookie($client);

        // The matrix rule `^/api` needs ROLE_ADMIN, and `^/api/invite/process$` needs ROLE_PLAYER.
        // Symfony does not count a remember-me token as fully authenticated, so a denied
        // route ends in the entry point (401) instead of access denied (403).
        $client->request(Request::METHOD_POST, '/api/room/create');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->dropSessionCookie($client);
        $client->request(Request::METHOD_POST, '/api/invite/process');
        self::assertNotContains($client->getResponse()->getStatusCode(), [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN]);
        self::assertFalse(str_contains((string) $client->getResponse()->getContent(), 'AUTHENTICATION_REQUIRED'));
    }

    public function testVisitWithALiveSessionRefreshesTheTokenAndCookie(): void
    {
        $client = static::createClient();
        $player = $this->createUser('session', ['ROLE_PLAYER']);

        $this->logIn($client, $player);
        $this->backdateTokens(self::SEVEN_DAYS / 2);

        $client->request(Request::METHOD_GET, '/api/login/success');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertEqualsWithDelta(time(), $this->lastUsedTimestamp(), 60);
        $cookie = $this->rememberCookie($client);
        self::assertNotNull($cookie);
        self::assertEqualsWithDelta(time() + self::SEVEN_DAYS, $cookie->getExpiresTime(), 60);
    }

    public function testAdminLoginWithRememberMeParameterStillGetsNoCookie(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('adminparam', ['ROLE_ADMIN']);

        SameOriginLogin::postFields($client, [
            '_username' => $admin->getEmail(),
            '_password' => self::LOGIN_PASSWORD,
            '_remember_me' => '1',
        ]);

        self::assertResponseIsSuccessful();
        self::assertNull($this->rememberCookie($client));
        self::assertSame(0, $this->tokenCount());
    }

    public function testPlayerLoginPurgesExpiredTokens(): void
    {
        $client = static::createClient();
        $player = $this->createUser('purge', ['ROLE_PLAYER']);
        $connection = static::getContainer()->get(Connection::class);
        $insert = 'INSERT INTO rememberme_token (series, value, lastUsed, class, username) VALUES (:series, :value, :lastUsed, :class, :username)';
        $connection->executeStatement($insert, [
            'series' => 'expired-series',
            'value' => 'unused',
            'lastUsed' => gmdate('Y-m-d H:i:s', time() - self::SEVEN_DAYS - 3600),
            'class' => User::class,
            'username' => 'gone@test.dev',
        ]);
        $connection->executeStatement($insert, [
            'series' => 'live-series',
            'value' => 'unused',
            'lastUsed' => gmdate('Y-m-d H:i:s', time() - 3600),
            'class' => User::class,
            'username' => 'still-here@test.dev',
        ]);

        $this->logIn($client, $player);

        $series = $connection->fetchFirstColumn('SELECT series FROM rememberme_token ORDER BY series');
        self::assertNotContains('expired-series', $series);
        self::assertContains('live-series', $series);
        self::assertCount(2, $series);
    }

    public function testAdminLoginGetsNoRememberCookie(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin', ['ROLE_ADMIN']);

        $this->logIn($client, $admin);

        self::assertNull($this->rememberCookie($client));
        self::assertSame(0, $this->tokenCount());
    }

    public function testLogoutDeletesTheTokenAndClearsTheCookie(): void
    {
        $client = static::createClient();
        $player = $this->createUser('logout', ['ROLE_PLAYER']);

        $this->logIn($client, $player);
        self::assertSame(1, $this->tokenCount());

        $client->request(Request::METHOD_POST, '/api/logout');

        self::assertSame(0, $this->tokenCount());
        self::assertNull($this->rememberCookie($client));
        $this->dropSessionCookie($client);
        $client->request(Request::METHOD_GET, '/api/login/success');
        self::assertFalse($this->isAuthenticated($client));
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $label, array $roles): User
    {
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $user = TestUserFactory::create($entityManager, self::USER_PREFIX, $label, $roles);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::LOGIN_PASSWORD));
        $entityManager->flush();

        return $user;
    }

    private function logIn(KernelBrowser $client, User $user): void
    {
        SameOriginLogin::post($client, (string) $user->getEmail(), self::LOGIN_PASSWORD);
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
    }

    private function isAuthenticated(KernelBrowser $client): bool
    {
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return true === $payload['success'];
    }

    private function dropSessionCookie(KernelBrowser $client): void
    {
        $jar = $client->getCookieJar();
        foreach ($jar->all() as $cookie) {
            if (self::REMEMBER_COOKIE !== $cookie->getName()) {
                $jar->expire($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }
    }

    private function rememberCookie(KernelBrowser $client): ?Cookie
    {
        return $client->getCookieJar()->get(self::REMEMBER_COOKIE);
    }

    private function backdateTokens(int $seconds): void
    {
        static::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE rememberme_token SET lastUsed = :lastUsed',
            ['lastUsed' => gmdate('Y-m-d H:i:s', time() - $seconds)],
        );
    }

    private function lastUsedTimestamp(): int
    {
        $lastUsed = static::getContainer()->get(Connection::class)->fetchOne('SELECT lastUsed FROM rememberme_token');

        return (int) strtotime($lastUsed.' UTC');
    }

    private function tokenCount(): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM rememberme_token');
    }
}
