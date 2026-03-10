<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\ApiAuthenticationSuccessHandler;
use App\Service\Security\SecurityServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class ApiAuthenticationSuccessHandlerTest extends TestCase
{
    /**
     * @return void
     */
    public function testApiRequestReturnsJsonResponseFromSecurityService(): void
    {
        $securityService = $this->createMock(SecurityServiceInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $handler = new ApiAuthenticationSuccessHandler($securityService, $urlGenerator);

        $request = Request::create('/api/login', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $user = $this->createMock(User::class);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $expectedResponse = new JsonResponse(['success' => true, 'redirect' => '/joined']);

        $securityService
            ->expects($this->once())
            ->method('buildLoginSuccessResponse')
            ->with($user, $request->getSession())
            ->willReturn($expectedResponse);

        self::assertSame($expectedResponse, $handler->onAuthenticationSuccess($request, $token));
    }

    /**
     * @return void
     */
    public function testNonApiRequestRedirectsToLoginSuccess(): void
    {
        $securityService = $this->createMock(SecurityServiceInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $handler = new ApiAuthenticationSuccessHandler($securityService, $urlGenerator);

        $request = Request::create('/login', 'POST');
        $token = $this->createMock(TokenInterface::class);

        $urlGenerator
            ->expects($this->once())
            ->method('generate')
            ->with('login_success')
            ->willReturn('/api/login/success');

        $response = $handler->onAuthenticationSuccess($request, $token);

        self::assertSame('/api/login/success', $response->headers->get('Location'));
    }
}