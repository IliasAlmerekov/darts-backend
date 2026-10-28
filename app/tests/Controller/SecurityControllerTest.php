<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\SecurityController;
use App\Entity\User;
use App\Exception\Security\UserNotAuthenticatedException;
use Override;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

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
        string $email,
        array $roles = ['ROLE_USER']
    ): User&MockObject {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getUserIdentifier')->willReturn($email);
        $user->method('getUsername')->willReturn($username);
        $user->method('getEmail')->willReturn($email);
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
        $user = $this->createUserMock(1, 'testuser', 'testuser@example.com');
        $tokenStorage = $this->createTokenStorageWithUser($user);

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

        $response = $this->controller->login();

        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertEquals(1, $response['id']);
        $this->assertEquals('testuser@example.com', $response['email']);
        $this->assertEquals('testuser', $response['username']);
        $this->assertEquals('/api/login/success', $response['redirect']);
    }

    /**
     * Test: Login - User nicht eingeloggt -> 401 AUTHENTICATION_REQUIRED
     */
    public function testLoginThrowsNotAuthenticatedWhenUserNotLoggedIn(): void
    {
        $tokenStorage = $this->createTokenStorageWithUser(null);

        $this->container->method('has')->willReturnCallback(function ($service) {
            return $service === 'security.token_storage';
        });

        $this->container->method('get')->willReturnCallback(function ($service) use ($tokenStorage) {
            return match ($service) {
                'security.token_storage' => $tokenStorage,
                default => throw new \RuntimeException("Service $service not found")
            };
        });

        $this->expectException(UserNotAuthenticatedException::class);

        $this->controller->login();
    }
}
