<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\ApiAuthenticationFailureHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class ApiAuthenticationFailureHandlerTest extends TestCase
{
    /**
     * @return void
     */
    public function testApiRequestReturnsJsonInsteadOfRedirect(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $handler = new ApiAuthenticationFailureHandler($urlGenerator);

        $request = Request::create('/api/login', 'POST', [
            '_username' => 'alice@example.com',
        ]);

        $response = $handler->onAuthenticationFailure($request, new AuthenticationException('Bad credentials.'));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('{"success":false,"last_username":"alice@example.com","error":"Bad credentials.","status":401}', $response->getContent());
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