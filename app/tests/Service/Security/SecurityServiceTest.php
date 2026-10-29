<?php

declare(strict_types=1);

namespace App\Tests\Service\Security;

use App\Dto\LoginSuccessDto;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Exception\Game\GameAlreadyStartedException;
use App\Repository\GamePlayersRepositoryInterface;
use App\Service\Invitation\InvitationServiceInterface;
use App\Service\Security\SecurityService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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
        $this->service = new SecurityService($this->invitationService, $this->gamePlayersRepository);
    }

    public function testAdminGoesToStartAndIgnoresTheInvitation(): void
    {
        $user = $this->userWithIdAndRoles(1, ['ROLE_ADMIN']);
        $this->invitationService->expects(self::never())->method('joinInvitedGame');

        self::assertEquals(new LoginSuccessDto($user, '/start'), $this->service->resolveLoginSuccess($user, 77));
    }

    public function testInvitedPlayerJoinsTheGame(): void
    {
        $user = $this->userWithIdAndRoles(2, ['ROLE_PLAYER']);
        $this->invitationService
            ->expects(self::once())
            ->method('joinInvitedGame')
            ->with(77, $user)
            ->willReturn(77);

        self::assertEquals(new LoginSuccessDto($user, '/joined', gameId: 77), $this->service->resolveLoginSuccess($user, 77));
    }

    public function testFailedInvitationJoinReportsTheErrorCode(): void
    {
        $user = $this->userWithIdAndRoles(4, ['ROLE_PLAYER']);
        $this->invitationService
            ->expects(self::once())
            ->method('joinInvitedGame')
            ->willThrowException(new GameAlreadyStartedException());
        $this->gamePlayersRepository->expects(self::never())->method('findCurrentGameForPlayer');

        self::assertEquals(
            new LoginSuccessDto($user, '/playerprofile', invitationError: 'GAME_ALREADY_STARTED'),
            $this->service->resolveLoginSuccess($user, 78),
        );
    }

    public function testPlayerWithCurrentGameRedirectsToJoined(): void
    {
        $user = $this->userWithIdAndRoles(5, ['ROLE_PLAYER']);
        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('findCurrentGameForPlayer')
            ->with(5)
            ->willReturn(['gameId' => 42, 'status' => GameStatus::Lobby]);

        self::assertEquals(new LoginSuccessDto($user, '/joined', gameId: 42), $this->service->resolveLoginSuccess($user, null));
    }

    public function testPlayerWithoutCurrentGameRedirectsToProfile(): void
    {
        $user = $this->userWithIdAndRoles(3, ['ROLE_PLAYER']);
        $this->gamePlayersRepository->method('findCurrentGameForPlayer')->willReturn(null);
        $this->invitationService->expects(self::never())->method('joinInvitedGame');

        self::assertEquals(new LoginSuccessDto($user, '/playerprofile'), $this->service->resolveLoginSuccess($user, null));
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
