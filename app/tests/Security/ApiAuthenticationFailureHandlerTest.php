<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\ApiAuthenticationFailureHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;

final class ApiAuthenticationFailureHandlerTest extends TestCase
{
    /**
     * @return void
     */
    public function testApiRequestReturnsOneInvalidCredentialsBodyForEveryCause(): void
    {
        $handler = new ApiAuthenticationFailureHandler($this->createStub(UrlGeneratorInterface::class), $this->createStub(LoggerInterface::class));
        $request = Request::create('/api/login', 'POST', ['_username' => 'alice@example.com']);

        $bodies = [];
        foreach ([new BadCredentialsException('Bad credentials.'), new AuthenticationException('The presented password is invalid.')] as $cause) {
            $response = $handler->onAuthenticationFailure($request, $cause);
            self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
            $bodies[] = $response->getContent();
        }

        self::assertSame('{"success":false,"error":"LOGIN_INVALID_CREDENTIALS","message":"Invalid email or password"}', $bodies[0]);
        self::assertSame($bodies[0], $bodies[1]);
    }

    public function testFailureLogsTheRealCauseButNotThePassword(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->anything(),
                $this->callback(static fn (array $context): bool => BadCredentialsException::class === $context['exception_class']
                    && 'The presented password is invalid.' === $context['reason']
                    && !str_contains((string) json_encode($context), 'hunter2')),
            );
        $handler = new ApiAuthenticationFailureHandler($this->createStub(UrlGeneratorInterface::class), $logger);
        $request = Request::create('/api/login', 'POST', ['_username' => 'alice@example.com', '_password' => 'hunter2']);

        $handler->onAuthenticationFailure($request, new BadCredentialsException('The presented password is invalid.'));
    }

    public function testTooManyLoginAttemptsReturns429WithRetryAfter(): void
    {
        $handler = new ApiAuthenticationFailureHandler($this->createStub(UrlGeneratorInterface::class), $this->createStub(LoggerInterface::class));
        $request = Request::create('/api/login', 'POST', ['_username' => 'alice@example.com']);

        $response = $handler->onAuthenticationFailure($request, new TooManyLoginAttemptsAuthenticationException(2));

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertSame('120', $response->headers->get('Retry-After'));
        self::assertSame(
            '{"success":false,"error":"LOGIN_RATE_LIMITED","message":"Too many failed login attempts. Try again later."}',
            $response->getContent(),
        );
    }

    public function testTooManyLoginAttemptsLogsTheRealCause(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->anything(),
                $this->callback(static fn (array $context): bool => TooManyLoginAttemptsAuthenticationException::class === $context['exception_class']
                    && 'Too many failed login attempts, please try again in %minutes% minutes.' === $context['reason']),
            );
        $handler = new ApiAuthenticationFailureHandler($this->createStub(UrlGeneratorInterface::class), $logger);

        $handler->onAuthenticationFailure(Request::create('/api/login', 'POST'), new TooManyLoginAttemptsAuthenticationException(2));
    }

    public function testTooManyLoginAttemptsWithoutThresholdHasNoRetryAfter(): void
    {
        $handler = new ApiAuthenticationFailureHandler($this->createStub(UrlGeneratorInterface::class), $this->createStub(LoggerInterface::class));

        $response = $handler->onAuthenticationFailure(Request::create('/api/login', 'POST'), new TooManyLoginAttemptsAuthenticationException());

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertFalse($response->headers->has('Retry-After'));
    }

    /**
     * @return void
     */
    public function testNonApiRequestRedirectsToLoginRoute(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $handler = new ApiAuthenticationFailureHandler($urlGenerator, $this->createStub(LoggerInterface::class));

        $request = Request::create('/login', 'POST');

        $urlGenerator
            ->expects($this->once())
            ->method('generate')
            ->with('app_login')
            ->willReturn('/api/login');

        $response = $handler->onAuthenticationFailure($request, new AuthenticationException('Bad credentials.'));

        self::assertSame('/api/login', $response->headers->get('Location'));
    }
}
