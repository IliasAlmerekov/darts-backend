<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * A cache-backed limiter reads, increments and saves its counter in separate steps. Without a
 * lock, two workers can both take the last token and exceed the limit. Every limiter must
 * therefore get the shared lock factory.
 */
final class RateLimiterLockTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function limiters(): iterable
    {
        yield 'registration' => ['limiter.registration'];
        yield 'login per email and IP' => ['limiter._login_local_main'];
        yield 'login per IP' => ['limiter._login_global_main'];
    }

    #[DataProvider('limiters')]
    public function testLimiterSerializesStateUpdatesWithALock(string $serviceId): void
    {
        $limiter = static::getContainer()->get($serviceId);
        self::assertInstanceOf(RateLimiterFactory::class, $limiter);

        $lockFactory = (new ReflectionProperty(RateLimiterFactory::class, 'lockFactory'))->getValue($limiter);

        self::assertInstanceOf(LockFactory::class, $lockFactory);
    }

    public function testLockDsnDefaultsToFlockWhenTheEnvironmentLacksIt(): void
    {
        // The prod image ships without .env, so the config must supply the fallback itself.
        self::assertSame('flock', static::getContainer()->getParameter('env(LOCK_DSN)'));
    }
}
