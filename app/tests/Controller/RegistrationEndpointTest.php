<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Posts to /api/register through the real kernel, form, and validator, so a constraint
 * that throws while the form is built shows up as a failing status code.
 */
final class RegistrationEndpointTest extends WebTestCase
{
    public function testValidPayloadRegistersPlayer(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(4));
        $email = sprintf('register-%s@test.dev', $suffix);

        $payload = $this->post($client, [
            'email' => $email,
            'username' => sprintf('register_%s', $suffix),
            'plainPassword' => 'secret123',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $client->getResponse()->getContent());
        self::assertTrue($payload['success']);

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);
        self::assertContains('ROLE_PLAYER', $user->getRoles());
    }

    public function testBlankPayloadReturnsFieldErrors(): void
    {
        $client = static::createClient();

        $payload = $this->post($client, ['email' => '', 'username' => '', 'plainPassword' => '']);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, (string) $client->getResponse()->getContent());
        self::assertFalse($payload['success']);
        self::assertSame(['Please enter an email address'], $payload['errors']['email'] ?? null);
        self::assertSame(['Please enter a username'], $payload['errors']['username'] ?? null);
        self::assertSame(['Please enter a password'], $payload['errors']['plainPassword'] ?? null);
    }

    public function testInvalidEmailReturnsFieldError(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(4));

        $payload = $this->post($client, [
            'email' => 'not-an-email',
            'username' => sprintf('register_%s', $suffix),
            'plainPassword' => 'secret123',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, (string) $client->getResponse()->getContent());
        self::assertSame(['Please enter a valid email address'], $payload['errors']['email'] ?? null);
    }

    /**
     * @param array<string, string> $body
     *
     * @return array<array-key, mixed>
     */
    private function post(KernelBrowser $client, array $body): array
    {
        $client->request(Request::METHOD_POST, '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
