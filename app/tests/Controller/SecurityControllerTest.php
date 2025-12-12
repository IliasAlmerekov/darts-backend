<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\SecurityController;
use App\Entity\User;
use Override;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

#[AllowMockObjectsWithoutExpectations]
class SecurityControllerTest extends TestCase
{
    private SecurityController $controller;
    private ContainerInterface&MockObject $container;

    #[Override]
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
     * Test: Login - User ist bereits eingeloggt -> Returns JSON with success
     */
    public function testLoginRedirectsWhenUserAlreadyLoggedIn(): void
    {
        $user = $this->createUserMock(1, 'testuser');
        $tokenStorage = $this->createTokenStorageWithUser($user);

        $authenticationUtils = $this->createMock(AuthenticationUtils::class);

        // Container Konfiguration
        $this->container->method('has')->willReturnCallback(function ($service) {
            return $service === 'security.token_storage';
        });

        $this->container->method('get')->willReturnCallback(function ($service) use ($tokenStorage) {
            return match ($service) {
                'security.token_storage' => $tokenStorage,
                default => throw new \RuntimeException("Service $service not found")
            };
        });

        $response = $this->controller->login($authenticationUtils);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $content = $response->getContent();
        $this->assertNotFalse($content);
        $data = json_decode($content, true);
        $this->assertTrue($data['success']);
        $this->assertEquals(1, $data['id']);
        $this->assertEquals('testuser', $data['username']);
        $this->assertEquals('/api/login/success', $data['redirect']);
    }

    /**
     * Test: Login - User nicht eingeloggt -> Returns JSON with error info
     */
    public function testLoginRendersFormWhenUserNotLoggedIn(): void
    {
        // Arrange
        $tokenStorage = $this->createTokenStorageWithUser(null);

        $authenticationUtils = $this->createMock(AuthenticationUtils::class);
        $authenticationUtils->method('getLastAuthenticationError')->willReturn(null);
        $authenticationUtils->method('getLastUsername')->willReturn('john@example.com');

        // Container Konfiguration
        $this->container->method('has')->willReturnCallback(function ($service) {
            return $service === 'security.token_storage';
        });

        $this->container->method('get')->willReturnCallback(function ($service) use ($tokenStorage) {
            return match ($service) {
                'security.token_storage' => $tokenStorage,
                default => throw new \RuntimeException("Service $service not found")
            };
        });

        $response = $this->controller->login($authenticationUtils);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $content = $response->getContent();
        $this->assertNotFalse($content);
        $data = json_decode($content, true);
        $this->assertFalse($data['success']);
        $this->assertEquals('john@example.com', $data['last_username']);
        $this->assertNull($data['error']);
    }
}
