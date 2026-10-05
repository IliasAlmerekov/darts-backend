<?php

declare(strict_types=1);

namespace App\Tests\Service\Security;

use App\Entity\Game;
use App\Entity\User;
use App\Exception\Game\GameIdMissingException;
use App\Exception\Security\SecurityAccessDeniedException;
use App\Exception\Security\UserNotAuthenticatedException;
use App\Repository\GamePlayersRepositoryInterface;
use App\Service\Security\GameAccessService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Pins the current behaviour of GameAccessService, branch by branch.
 */
#[AllowMockObjectsWithoutExpectations]
final class GameAccessServiceTest extends TestCase
{
    private const int GAME_ID = 42;
    private const int USER_ID = 7;

    private Security&MockObject $security;
    private GamePlayersRepositoryInterface&MockObject $gamePlayersRepository;
    private GameAccessService $service;

    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $this->service = new GameAccessService($this->security, $this->gamePlayersRepository);
    }

    public function testRequireAuthenticatedUserReturnsTheAppUser(): void
    {
        $user = $this->createUser(self::USER_ID);
        $this->givenUser($user);

        self::assertSame($user, $this->service->requireAuthenticatedUser());
    }

    public function testRequireAuthenticatedUserRejectsAnonymousCaller(): void
    {
        $this->givenUser(null);

        $this->expectException(UserNotAuthenticatedException::class);
        $this->service->requireAuthenticatedUser();
    }

    public function testRequireAuthenticatedUserRejectsAUserOfAnotherClass(): void
    {
        $this->givenUser(new InMemoryUser('memory', null, ['ROLE_ADMIN']));

        $this->expectException(UserNotAuthenticatedException::class);
        $this->service->requireAuthenticatedUser();
    }

    public function testAssertAdminReturnsTheAdmin(): void
    {
        $user = $this->createUser(self::USER_ID);
        $this->givenUser($user);
        $this->givenAdmin(true);

        self::assertSame($user, $this->service->assertAdmin());
    }

    public function testAssertAdminRejectsANonAdmin(): void
    {
        $this->givenUser($this->createUser(self::USER_ID));
        $this->givenAdmin(false);

        $this->expectException(SecurityAccessDeniedException::class);
        $this->service->assertAdmin();
    }

    public function testAssertAdminRejectsAnonymousCallerBeforeCheckingTheRole(): void
    {
        $this->givenUser(null);
        $this->security->expects(self::never())->method('isGranted');

        $this->expectException(UserNotAuthenticatedException::class);
        $this->service->assertAdmin();
    }

    public function testAssertPlayerInGameOrAdminRejectsAnonymousCaller(): void
    {
        $this->givenUser(null);
        $this->gamePlayersRepository->expects(self::never())->method('isPlayerInGame');

        $this->expectException(UserNotAuthenticatedException::class);
        $this->service->assertPlayerInGameOrAdmin($this->createGame(self::GAME_ID));
    }

    public function testAssertPlayerInGameOrAdminLetsTheAdminThroughWithoutAMembershipLookup(): void
    {
        $user = $this->createUser(self::USER_ID);
        $this->givenUser($user);
        $this->givenAdmin(true);
        $this->gamePlayersRepository->expects(self::never())->method('isPlayerInGame');

        self::assertSame($user, $this->service->assertPlayerInGameOrAdmin($this->createGame(self::GAME_ID)));
    }

    public function testAssertPlayerInGameOrAdminLetsTheAdminThroughEvenWhenTheGameHasNoId(): void
    {
        $user = $this->createUser(self::USER_ID);
        $this->givenUser($user);
        $this->givenAdmin(true);

        self::assertSame($user, $this->service->assertPlayerInGameOrAdmin($this->createGame(null)));
    }

    public function testAssertPlayerInGameOrAdminRejectsAGameWithoutIdForANonAdmin(): void
    {
        $this->givenUser($this->createUser(self::USER_ID));
        $this->givenAdmin(false);
        $this->gamePlayersRepository->expects(self::never())->method('isPlayerInGame');

        $this->expectException(GameIdMissingException::class);
        $this->service->assertPlayerInGameOrAdmin($this->createGame(null));
    }

    public function testAssertPlayerInGameOrAdminRejectsAUserWithoutId(): void
    {
        $this->givenUser($this->createUser(null));
        $this->givenAdmin(false);
        $this->gamePlayersRepository->expects(self::never())->method('isPlayerInGame');

        $this->expectException(SecurityAccessDeniedException::class);
        $this->service->assertPlayerInGameOrAdmin($this->createGame(self::GAME_ID));
    }

    public function testAssertPlayerInGameOrAdminReturnsTheParticipant(): void
    {
        $user = $this->createUser(self::USER_ID);
        $this->givenUser($user);
        $this->givenAdmin(false);
        $this->gamePlayersRepository->expects(self::once())
            ->method('isPlayerInGame')
            ->with(self::GAME_ID, self::USER_ID)
            ->willReturn(true);

        self::assertSame($user, $this->service->assertPlayerInGameOrAdmin($this->createGame(self::GAME_ID)));
    }

    public function testAssertPlayerInGameOrAdminRejectsAPlayerOutsideTheGame(): void
    {
        $this->givenUser($this->createUser(self::USER_ID));
        $this->givenAdmin(false);
        $this->gamePlayersRepository->expects(self::once())
            ->method('isPlayerInGame')
            ->with(self::GAME_ID, self::USER_ID)
            ->willReturn(false);

        $this->expectException(SecurityAccessDeniedException::class);
        $this->service->assertPlayerInGameOrAdmin($this->createGame(self::GAME_ID));
    }

    public function testAssertPlayerMatchesLetsTheAdminActForAnyPlayer(): void
    {
        $this->givenAdmin(true);

        $this->service->assertPlayerMatches($this->createUser(self::USER_ID), self::USER_ID + 1);
    }

    public function testAssertPlayerMatchesLetsTheAdminThroughEvenWithoutAUserId(): void
    {
        $this->givenAdmin(true);

        $this->service->assertPlayerMatches($this->createUser(null), self::USER_ID);
    }

    public function testAssertPlayerMatchesAcceptsThePlayerThemself(): void
    {
        $this->givenAdmin(false);

        $this->service->assertPlayerMatches($this->createUser(self::USER_ID), self::USER_ID);
    }

    public function testAssertPlayerMatchesRejectsAnotherPlayer(): void
    {
        $this->givenAdmin(false);

        $this->expectException(SecurityAccessDeniedException::class);
        $this->service->assertPlayerMatches($this->createUser(self::USER_ID), self::USER_ID + 1);
    }

    public function testAssertPlayerMatchesRejectsAUserWithoutId(): void
    {
        $this->givenAdmin(false);

        $this->expectException(SecurityAccessDeniedException::class);
        $this->service->assertPlayerMatches($this->createUser(null), self::USER_ID);
    }

    private function givenUser(?UserInterface $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function givenAdmin(bool $isAdmin): void
    {
        $this->security->expects(self::atLeastOnce())
            ->method('isGranted')
            ->with('ROLE_ADMIN')
            ->willReturn($isAdmin);
    }

    private function createUser(?int $id): User
    {
        $user = (new User())
            ->setUsername('access_user')
            ->setEmail('access-user@test.dev')
            ->setPassword('unused')
            ->setRoles([]);

        if (null !== $id) {
            (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        }

        return $user;
    }

    private function createGame(?int $gameId): Game
    {
        $game = new Game();
        if (null !== $gameId) {
            $game->setGameId($gameId);
        }

        return $game;
    }
}
