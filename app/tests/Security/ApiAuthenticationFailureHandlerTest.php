<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\ApiAuthenticationFailureHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;

final class ApiAuthenticationFailureHandlerTest extends TestCase
{
    /**
     * @return void
     */
    public function testApiRequestReturnsJsonInsteadOfRedirect(): void
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $handler = new ApiAuthenticationFailureHandler($urlGenerator);

        $request = Request::create('/api/login', 'POST', [
            '_username' => 'alice@example.com',
        ]);

        $response = $handler->onAuthenticationFailure($request, new AuthenticationException('Bad credentials.'));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('{"success":false,"last_username":"alice@example.com","error":"Bad credentials.","status":401}', $response->getContent());
    }

    public function testTooManyLoginAttemptsReturns429WithRetryAfter(): void
    {
        $handler = new ApiAuthenticationFailureHandler($this->createStub(UrlGeneratorInterface::class));
        $request = Request::create('/api/login', 'POST', ['_username' => 'alice@example.com']);

        $response = $handler->onAuthenticationFailure($request, new TooManyLoginAttemptsAuthenticationException(2));

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertSame('120', $response->headers->get('Retry-After'));
        self::assertSame(
            '{"success":false,"last_username":"alice@example.com","error":"Too many failed login attempts, please try again in 2 minutes.","status":429}',
            $response->getContent(),
        );
    }

    public function testTooManyLoginAttemptsWithoutThresholdHasNoRetryAfter(): void
    {
        $handler = new ApiAuthenticationFailureHandler($this->createStub(UrlGeneratorInterface::class));

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
        $handler = new ApiAuthenticationFailureHandler($urlGenerator);

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
