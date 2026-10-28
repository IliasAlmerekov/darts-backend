<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Tests\Support\SameOriginLogin;
use App\Tests\Support\TestUserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Unsafe API requests from another origin answer 403 CROSS_ORIGIN_REQUEST (#135), and
 * /api/csrf no longer exists.
 */
final class CrossOriginRequestTest extends WebTestCase
{
    private const string CROSS_ORIGIN_BODY = '{"success":false,"error":"CROSS_ORIGIN_REQUEST","message":"Cross-origin request rejected"}';
    private const string WRITE_ROUTE = '/api/room/create';

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function foreignOrigins(): iterable
    {
        yield 'foreign Origin' => [['HTTP_ORIGIN' => 'https://evil.example']];
        yield 'foreign Origin on another port' => [['HTTP_ORIGIN' => 'http://localhost:5173']];
        yield 'Sec-Fetch-Site cross-site' => [['HTTP_SEC_FETCH_SITE' => 'cross-site']];
        yield 'Sec-Fetch-Site same-site' => [['HTTP_SEC_FETCH_SITE' => 'same-site']];
        yield 'Sec-Fetch-Site none' => [['HTTP_SEC_FETCH_SITE' => 'none']];
        yield 'same-origin Origin overruled by cross-site Sec-Fetch-Site' => [['HTTP_ORIGIN' => 'http://localhost', 'HTTP_SEC_FETCH_SITE' => 'cross-site']];
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('foreignOrigins')]
    public function testLoginFromAnotherOriginAnswers403(array $server): void
    {
        $client = static::createClient();
        $user = $this->createUser();

        SameOriginLogin::post($client, $user->getEmail() ?? '', 'secret', $server);

        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        self::assertSame(self::CROSS_ORIGIN_BODY, $client->getResponse()->getContent());
    }

    public function testLoginFromTheOwnOriginWithTheTokenSucceeds(): void
    {
        $client = static::createClient();
        $user = $this->createUser();

        SameOriginLogin::post($client, $user->getEmail() ?? '', 'secret');

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
    }

    public function testLoginWithSecFetchSiteSameOriginSucceeds(): void
    {
        $client = static::createClient();
        $user = $this->createUser();

        $client->request(Request::METHOD_POST, '/api/login', ['_username' => $user->getEmail(), '_password' => 'secret', '_csrf_token' => 'csrf-token'], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
    }

    public function testLoginWithoutTheTokenFieldAnswers403(): void
    {
        $client = static::createClient();
        $user = $this->createUser();

        $client->request(Request::METHOD_POST, '/api/login', ['_username' => $user->getEmail(), '_password' => 'secret'], [], SameOriginLogin::SERVER);

        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        self::assertSame(self::CROSS_ORIGIN_BODY, $client->getResponse()->getContent());
    }

    public function testLoginWithAWrongTokenValueAnswers403(): void
    {
        $client = static::createClient();
        $user = $this->createUser();

        $client->request(Request::METHOD_POST, '/api/login', ['_username' => $user->getEmail(), '_password' => 'secret', '_csrf_token' => 'x'], [], SameOriginLogin::SERVER);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testLoginWithoutAnyOriginHeaderAnswers403(): void
    {
        $client = static::createClient();
        $user = $this->createUser();

        $client->request(Request::METHOD_POST, '/api/login', ['_username' => $user->getEmail(), '_password' => 'secret', '_csrf_token' => 'csrf-token']);

        self::assertSame(self::CROSS_ORIGIN_BODY, $client->getResponse()->getContent());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('foreignOrigins')]
    public function testWriteRouteFromAnotherOriginAnswers403BeforeTheFirewall(array $server): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_POST, self::WRITE_ROUTE, [], [], $server);

        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        self::assertSame(self::CROSS_ORIGIN_BODY, $client->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function acceptedOrigins(): iterable
    {
        yield 'Sec-Fetch-Site same-origin' => [['HTTP_SEC_FETCH_SITE' => 'same-origin']];
        yield 'matching Origin' => [['HTTP_ORIGIN' => 'http://localhost']];
        yield 'matching Origin, Sec-Fetch-Site same-origin' => [['HTTP_ORIGIN' => 'http://localhost', 'HTTP_SEC_FETCH_SITE' => 'same-origin']];
        yield 'neither header' => [[]];
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('acceptedOrigins')]
    public function testWriteRouteFromTheOwnOriginOrWithNoHeaderBehavesAsBefore(array $server): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_POST, self::WRITE_ROUTE, [], [], $server);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAdminWriteFromTheOwnOriginReachesTheController(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = TestUserFactory::create($entityManager, 'crossorigin', 'admin', ['ROLE_ADMIN']);
        $entityManager->flush();
        $client->loginUser($admin);

        $client->request(Request::METHOD_POST, self::WRITE_ROUTE, [], [], ['HTTP_ORIGIN' => 'http://localhost']);

        // The empty body is a 400 from the controller: the request passed the origin check and the firewall.
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testGetRouteIsNotCheckedForTheOrigin(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/api/health', [], [], ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_SEC_FETCH_SITE' => 'cross-site']);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    public function testRoutesOutsideTheApiAreNotChecked(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_POST, '/not-the-api', [], [], ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testCrossOriginLoginsDoNotUseUpTheLoginThrottle(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $user = $this->createUser();

        for ($i = 0; $i < 7; ++$i) {
            SameOriginLogin::post($client, $user->getEmail() ?? '', 'wrong', ['HTTP_ORIGIN' => 'https://evil.example', 'REMOTE_ADDR' => '203.0.113.55']);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        }
        SameOriginLogin::post($client, $user->getEmail() ?? '', 'secret', ['REMOTE_ADDR' => '203.0.113.55']);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    public function testCsrfTokenEndpointIsGone(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/api/csrf');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function createUser(): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = TestUserFactory::create($entityManager, 'crossorigin', 'player', ['ROLE_PLAYER']);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret'));
        $entityManager->flush();

        return $user;
    }
}
