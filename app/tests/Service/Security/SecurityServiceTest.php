<?php

declare(strict_types=1);

namespace App\Tests\Service\Security;

use App\Entity\User;
use App\Service\Invitation\InvitationServiceInterface;
use App\Service\Security\SecurityService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

#[AllowMockObjectsWithoutExpectations]
final class SecurityServiceTest extends TestCase
{
    private InvitationServiceInterface&MockObject $invitationService;
    private SecurityService $service;

    protected function setUp(): void
    {
        $this->invitationService = $this->createMock(InvitationServiceInterface::class);
        $this->service = new SecurityService($this->invitationService);
    }

    public function testAdminGetsStartRedirect(): void
    {
        $user = $this->userWithIdAndRoles(1, ['ROLE_ADMIN']);
        $user->setEmail('admin@test.dev');
        $session = $this->createMock(SessionInterface::class);

        $response = $this->service->buildLoginSuccessResponse($user, $session);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode($response->getContent() ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/start', parse_url($payload['redirect'], PHP_URL_PATH));
        self::assertSame($user->getId(), $payload['id']);
        self::assertSame($user->getEmail(), $payload['email']);
        self::assertSame($user->getUsername(), $payload['username']);
        self::assertSame($user->getStoredRoles(), $payload['roles']);
    }

    public function testInvitationFlowDelegatesToInvitationService(): void
    {
        $user = $this->userWithIdAndRoles(2, ['ROLE_PLAYER']);
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())->method('has')->with('invitation_uuid')->willReturn(true);

        $expected = new JsonResponse(['success' => true, 'redirect' => '/joined'], Response::HTTP_OK);
        $this->invitationService
            ->expects(self::once())
            ->method('processInvitation')
            ->with($session, $user)
            ->willReturn($expected);

        $response = $this->service->buildLoginSuccessResponse($user, $session);

        self::assertSame($expected, $response);
    }

    public function testDefaultFlowRedirectsToJoined(): void
    {
        $user = $this->userWithIdAndRoles(3, ['ROLE_PLAYER']);
        $user->setEmail('player@test.dev');
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())->method('has')->with('invitation_uuid')->willReturn(false);

        $response = $this->service->buildLoginSuccessResponse($user, $session);

        self::assertInstanceOf(JsonResponse::class, $response);
        $payload = json_decode($response->getContent() ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/joined', parse_url($payload['redirect'], PHP_URL_PATH));
        self::assertSame($user->getId(), $payload['id']);
        self::assertSame($user->getEmail(), $payload['email']);
        self::assertSame($user->getUsername(), $payload['username']);
        self::assertSame($user->getStoredRoles(), $payload['roles']);
    }

    private function userWithIdAndRoles(int $id, array $roles): User
    {
        $user = (new User())
            ->setUsername('u'.$id)
            ->setEmail('u'.$id.'@test.dev')
            ->setPassword('pw')
            ->setRoles($roles);

        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }
}
