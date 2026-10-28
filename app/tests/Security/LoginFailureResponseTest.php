<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\TestUserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A failed login answers the same bytes whether or not the account exists (#177).
 */
final class LoginFailureResponseTest extends WebTestCase
{
    private const string INVALID_CREDENTIALS = '{"success":false,"error":"LOGIN_INVALID_CREDENTIALS","message":"Invalid email or password"}';

    public function testUnknownEmailAndWrongPasswordGiveIdenticalStatusAndBody(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $known = TestUserFactory::create($entityManager, 'loginfail', 'known', ['ROLE_PLAYER']);
        $entityManager->flush();

        $client->request(Request::METHOD_POST, '/api/login', ['_username' => $known->getEmail(), '_password' => 'wrong-password'], [], ['REMOTE_ADDR' => '203.0.113.31', 'HTTP_ACCEPT' => 'application/json']);
        $knownResponse = $client->getResponse();
        $client->request(Request::METHOD_POST, '/api/login', ['_username' => 'nobody-'.bin2hex(random_bytes(4)).'@example.invalid', '_password' => 'wrong-password'], [], ['REMOTE_ADDR' => '203.0.113.32', 'HTTP_ACCEPT' => 'application/json']);
        $unknownResponse = $client->getResponse();

        self::assertSame(Response::HTTP_UNAUTHORIZED, $knownResponse->getStatusCode());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $unknownResponse->getStatusCode());
        self::assertSame(self::INVALID_CREDENTIALS, $knownResponse->getContent());
        self::assertSame($knownResponse->getContent(), $unknownResponse->getContent());
    }

    public function testGetLoginWithoutSessionAnswers401NotAuthenticated(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/api/login');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame(
            '{"success":false,"error":"AUTHENTICATION_REQUIRED","message":"Unauthorized"}',
            $client->getResponse()->getContent(),
        );
    }
}
