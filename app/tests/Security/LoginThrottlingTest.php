<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Tests\Support\SameOriginLogin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Login allows 5 failed attempts per minute for one email and IP, then answers 429.
 */
final class LoginThrottlingTest extends WebTestCase
{
    private const string PASSWORD = 'throttle-secret';
    private const string IP = '203.0.113.20';

    public function testSixthFailedLoginForOneEmailAndIpAnswers429(): void
    {
        $client = $this->throttledClient();
        $email = $this->createUser('victim')->getEmail();

        for ($i = 1; $i <= 5; ++$i) {
            $this->login($client, (string) $email, 'wrong-password');
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'Attempt '.$i);
        }
        $this->login($client, (string) $email, 'wrong-password');

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertSame(
            '{"success":false,"error":"LOGIN_RATE_LIMITED","message":"Too many failed login attempts. Try again later."}',
            $response->getContent(),
        );
        self::assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }

    public function testOtherEmailFromTheSameIpCanStillLogIn(): void
    {
        $client = $this->throttledClient();
        $locked = $this->createUser('locked')->getEmail();
        $admin = $this->createUser('admin')->getEmail();
        for ($i = 0; $i < 6; ++$i) {
            $this->login($client, (string) $locked, 'wrong-password');
        }
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        $this->login($client, (string) $admin, self::PASSWORD);

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
    }

    public function testEmailCaseVariantsShareOneBucket(): void
    {
        // MySQL compares emails case-insensitively, so each spelling hits the same account.
        $client = $this->throttledClient();
        $email = (string) $this->createUser('casing')->getEmail();
        $variants = [$email, strtoupper($email), ucfirst($email), strtoupper($email), $email];

        foreach ($variants as $i => $variant) {
            $this->login($client, $variant, 'wrong-password');
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'Attempt '.($i + 1));
        }
        $this->login($client, ucfirst($email), 'wrong-password');

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    private function throttledClient(): KernelBrowser
    {
        $client = static::createClient();
        // Limiter state lives in the kernel's in-memory pool, so keep one kernel per test.
        $client->disableReboot();

        return $client;
    }

    private function login(KernelBrowser $client, string $email, string $password): void
    {
        SameOriginLogin::post($client, $email, $password, ['REMOTE_ADDR' => self::IP]);
    }

    private function createUser(string $label): User
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(4));
        $user = (new User())
            ->setEmail(sprintf('throttle-%s-%s@test.dev', $label, $suffix))
            ->setUsername(sprintf('throttle_%s_%s', $label, $suffix))
            ->setRoles(['ROLE_PLAYER']);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
