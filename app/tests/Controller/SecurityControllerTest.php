<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\SecurityController;
use App\Entity\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;

class SecurityControllerTest extends TestCase
{
    private SecurityController $controller;
    private ContainerInterface&MockObject $container;

    protected function setUp(): void
    {
        $this->controller = new SecurityController();
        $this->container = $this->createMock(ContainerInterface::class);
        $this->controller->setContainer($this->container);
    }

    /**
     * Helper: Mock User erstellen
     */
    private function createUserMock(
        int $id,
        string $username,
        array $roles = ['ROLE_USER']
    ): User&MockObject {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getUserIdentifier')->willReturn($username);
        $user->method('getRoles')->willReturn($roles);
        $user->method('getStoredRoles')->willReturn($roles);
        return $user;
    }

    /**
     * Helper: Erstellt TokenStorage mit User
     */
    private function createTokenStorageWithUser(?User $user): TokenStorageInterface&MockObject
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);

        if ($user === null) {
            $tokenStorage->method('getToken')->willReturn(null);
        } else {
            $token = $this->createMock(TokenInterface::class);
            $token->method('getUser')->willReturn($user);
            $tokenStorage->method('getToken')->willReturn($token);
        }

        return $tokenStorage;
    }
    /**
     * Test: Login - User ist bereits eingeloggt -> Redirect zu login_success
     */
    public function testLoginRedirectsWhenUserAlreadyLoggedIn(): void
    {
        $user = $this->createUserMock(1, 'testuser');
        $tokenStorage = $this->createTokenStorageWithUser($user);

        $authenticationUtils = $this->createMock(AuthenticationUtils::class);

        $router = $this->createMock(RouterInterface::class);
        $router->expects($this->once())
            ->method('generate')
            ->with('login_success')
            ->willReturn('/api/login/success');

        // Container Konfiguration
        $this->container->method('has')->willReturnMap([
            ['security.token_storage', true],
            ['router', true],
        ]);

        $this->container->method('get')->willReturnCallback(function($service) use ($tokenStorage, $router) {
            return match($service) {
                'security.token_storage' => $tokenStorage,
                'router' => $router,
                default => null
            };
        });

        $response = $this->controller->login($authenticationUtils);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals('/api/login/success', $response->getTargetUrl());
    }

    /**
     * Test: Login - User nicht eingeloggt -> Zeigt Login-Form
     */
    public function testLoginRendersFormWhenUserNotLoggedIn(): void
    {
        // Arrange
        $tokenStorage = $this->createTokenStorageWithUser(null);

        $authenticationUtils = $this->createMock(AuthenticationUtils::class);
        $authenticationUtils->method('getLastAuthenticationError')->willReturn(null);
        $authenticationUtils->method('getLastUsername')->willReturn('john@example.com');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('security/login.html.twig', [
                'last_username' => 'john@example.com',
                'error' => null,
            ])
            ->willReturn('');

        // Container Konfiguration
        $this->container->method('has')->willReturnMap([
            ['security.token_storage', true],
            ['twig', true],
        ]);

        $this->container->method('get')->willReturnCallback(function($service) use ($tokenStorage, $twig) {
            return match($service) {
                'security.token_storage' => $tokenStorage,
                'twig' => $twig,
                default => null
            };
        });

        $response = $this->controller->login($authenticationUtils);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals('', $response->getContent());
    }
}
