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
        $body = $this->validBody();

        $payload = $this->postRegistration($client, $body, Response::HTTP_CREATED);

        self::assertTrue($payload['success']);
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $body['email']]);
        self::assertInstanceOf(User::class, $user);
        self::assertContains('ROLE_PLAYER', $user->getRoles());
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
