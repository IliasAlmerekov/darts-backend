<?php

declare(strict_types=1);

namespace App\Tests\Service\Security;

use App\Entity\User;
use App\Enum\GameStatus;
use App\Exception\Game\GameAlreadyStartedException;
use App\Repository\GamePlayersRepositoryInterface;
use App\Service\Invitation\InvitationServiceInterface;
use App\Service\Security\SecurityService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[AllowMockObjectsWithoutExpectations]
final class SecurityServiceTest extends TestCase
{
    private InvitationServiceInterface&MockObject $invitationService;
    private GamePlayersRepositoryInterface&MockObject $gamePlayersRepository;
    private SecurityService $service;

    protected function setUp(): void
    {
        $this->invitationService = $this->createMock(InvitationServiceInterface::class);
        $this->gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $this->service = new SecurityService($this->invitationService, $this->gamePlayersRepository, 'http://frontend.test');
    }

    public function testAdminGetsStartRedirect(): void
    {
        $user = $this->userWithIdAndRoles(1, ['ROLE_ADMIN']);
        $user->setEmail('admin@test.dev');
        $session = new Session(new MockArraySessionStorage());

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
        $session = $this->sessionWithInvitation(77);

        $this->invitationService
            ->expects(self::once())
            ->method('joinInvitedGame')
            ->with(77, $user)
            ->willReturn(77);

        $response = $this->service->buildLoginSuccessResponse($user, $session);

        $payload = json_decode($response->getContent() ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/joined', parse_url($payload['redirect'], PHP_URL_PATH));
        self::assertSame(77, $payload['gameId']);
        self::assertFalse($session->has('invitation_uuid'));
        self::assertFalse($session->has('game_id'));
    }

    public function testFailedInvitationJoinStillReturnsUserPayload(): void
    {
        $user = $this->userWithIdAndRoles(4, ['ROLE_PLAYER']);
        $session = $this->sessionWithInvitation(78);

        $this->invitationService
            ->expects(self::once())
            ->method('joinInvitedGame')
            ->willThrowException(new GameAlreadyStartedException());

        $response = $this->service->buildLoginSuccessResponse($user, $session);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode($response->getContent() ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success']);
        self::assertSame($user->getId(), $payload['id']);
        self::assertSame('/playerprofile', parse_url($payload['redirect'], PHP_URL_PATH));
        self::assertSame('GAME_ALREADY_STARTED', $payload['invitationError']);
    }

    public function testPlayerWithCurrentGameRedirectsToJoined(): void
    {
        $user = $this->userWithIdAndRoles(5, ['ROLE_PLAYER']);
        $session = new Session(new MockArraySessionStorage());
        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('findCurrentGameForPlayer')
            ->with(5)
            ->willReturn(['gameId' => 42, 'status' => GameStatus::Lobby]);

        $response = $this->service->buildLoginSuccessResponse($user, $session);

        $payload = json_decode($response->getContent() ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/joined', parse_url($payload['redirect'], PHP_URL_PATH));
        self::assertSame(42, $payload['gameId']);
    }

    public function testPlayerWithoutCurrentGameRedirectsToProfile(): void
    {
        $user = $this->userWithIdAndRoles(3, ['ROLE_PLAYER']);
        $user->setEmail('player@test.dev');
        $session = new Session(new MockArraySessionStorage());
        $this->gamePlayersRepository->method('findCurrentGameForPlayer')->willReturn(null);

        $response = $this->service->buildLoginSuccessResponse($user, $session);

        self::assertInstanceOf(JsonResponse::class, $response);
        $payload = json_decode($response->getContent() ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/playerprofile', parse_url($payload['redirect'], PHP_URL_PATH));
        self::assertArrayNotHasKey('gameId', $payload);
        self::assertSame($user->getId(), $payload['id']);
        self::assertSame($user->getEmail(), $payload['email']);
        self::assertSame($user->getUsername(), $payload['username']);
        self::assertSame($user->getStoredRoles(), $payload['roles']);
    }

    private function sessionWithInvitation(int $gameId): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('invitation_uuid', 'uuid-'.$gameId);
        $session->set('game_id', $gameId);

        return $session;
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
