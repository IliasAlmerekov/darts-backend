<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service\Invitation;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\Invitation;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Exception\Game\GameAlreadyStartedException;
use App\Exception\Game\GameJoinNotAllowedException;
use App\Exception\Game\GameNotFoundException;
use App\Exception\Game\GameRoomFullException;
use App\Exception\Security\UserNotAuthenticatedException;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\GameRepositoryInterface;
use App\Repository\InvitationRepositoryInterface;
use App\Repository\UserRepositoryInterface;
use App\Service\Invitation\InvitationService;
use App\Service\Player\PlayerManagementServiceInterface;
use App\Service\Security\GameAccessServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Routing\RouterInterface;

#[AllowMockObjectsWithoutExpectations]
final class InvitationServiceTest extends TestCase
{
    private InvitationRepositoryInterface&MockObject $invitationRepository;
    private GamePlayersRepositoryInterface&MockObject $gamePlayersRepository;
    private GameRepositoryInterface&MockObject $gameRepository;
    private UserRepositoryInterface&MockObject $userRepository;
    private PlayerManagementServiceInterface&MockObject $playerManagementService;
    private EntityManagerInterface&MockObject $entityManager;
    private RouterInterface&MockObject $router;
    private GameAccessServiceInterface&MockObject $gameAccessService;
    private InvitationService $service;

    protected function setUp(): void
    {
        $this->invitationRepository = $this->createMock(InvitationRepositoryInterface::class);
        $this->gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $this->gameRepository = $this->createMock(GameRepositoryInterface::class);
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->playerManagementService = $this->createMock(PlayerManagementServiceInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn(callable $func) => $func());
        $this->entityManager->method('contains')->willReturn(false);
        $this->router = $this->createMock(RouterInterface::class);
        $this->gameAccessService = $this->createMock(GameAccessServiceInterface::class);
        $this->gameAccessService->method('assertAdmin')->willReturn(new User());

        $this->service = new InvitationService(
            $this->invitationRepository,
            $this->gamePlayersRepository,
            $this->gameRepository,
            $this->userRepository,
            $this->playerManagementService,
            $this->entityManager,
            $this->router,
            $this->gameAccessService,
        );
    }

    public function testCreateOrGetReturnsExistingInvitation(): void
    {
        $game = (new Game())->setGameId(10);
        $existing = (new Invitation())->setUuid('uuid')->setGame($game);

        $this->invitationRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['game' => $game])
            ->willReturn($existing);

        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');

        $result = $this->service->createOrGetInvitation($game);

        self::assertSame($existing, $result);
    }

    public function testCreateOrGetCreatesNewInvitationWhenNotFound(): void
    {
        $game = (new Game())->setGameId(20);

        $this->invitationRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['game' => $game])
            ->willReturn(null);

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $result = $this->service->createOrGetInvitation($game);

        self::assertInstanceOf(Invitation::class, $result);
        self::assertSame(20, $result->getGameId());
        self::assertNotEmpty($result->getUuid());
    }

    public function testGetInvitationPayloadReturnsErrorWhenNoGameId(): void
    {
        $game = new Game(); // gameId null

        $payload = $this->service->getInvitationPayload($game);

        self::assertFalse($payload['success']);
        self::assertSame('Game not found', $payload['message']);
    }

    public function testGetInvitationPayloadReturnsUsersAndLink(): void
    {
        $game = (new Game())->setGameId(30);
        $invitation = (new Invitation())->setUuid('abc')->setGame($game);

        $player = new GamePlayers();
        $user = $this->createUserWithId(101, 'u', 'u@test');
        // emulate relation
        $player->setPlayer($user);

        $this->invitationRepository
            ->method('findOneBy')
            ->with(['game' => $game])
            ->willReturn($invitation);

        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('findByGameId')
            ->with(30)
            ->willReturn([$player]);

        $this->userRepository
            ->expects(self::never())
            ->method('findBy');

        $this->router
            ->expects(self::once())
            ->method('generate')
            ->with('join_invitation', ['uuid' => 'abc'])
            ->willReturn('/api/invite/join/abc');

        $payload = $this->service->getInvitationPayload($game);

        self::assertTrue($payload['success']);
        self::assertSame(30, $payload['gameId']);
        self::assertSame('/api/invite/join/abc', $payload['invitationLink']);
        self::assertSame([
            [
                'id' => $user->getId(),
                'username' => $user->getDisplayName(),
            ],
        ], $payload['users']);
    }

    public function testGetInvitationPayloadPropagatesParticipantListFailure(): void
    {
        $game = (new Game())->setGameId(31);
        $failure = new RuntimeException('participant list failed');

        $this->invitationRepository
            ->method('findOneBy')
            ->willReturn((new Invitation())->setUuid('def')->setGame($game));
        $this->gamePlayersRepository
            ->method('findByGameId')
            ->willThrowException($failure);

        try {
            $this->service->getInvitationPayload($game);
            self::fail('getInvitationPayload() must not swallow the participant list failure.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function testJoinInvitedGameRejectsAnUnsavedUser(): void
    {
        $this->gameRepository->expects(self::never())->method('find');

        $this->expectException(UserNotAuthenticatedException::class);

        $this->service->joinInvitedGame(50, new User());
    }

    public function testGetUsersForGameReturnsEmptyWhenNoGameId(): void
    {
        $game = new Game();

        $this->gamePlayersRepository->expects(self::never())->method('findByGameId');
        $this->userRepository->expects(self::never())->method('findBy');

        $result = $this->service->getUsersForGame($game);

        self::assertSame([], $result);
    }

    public function testGetUsersForGameReturnsEmptyWhenNoPlayers(): void
    {
        $game = (new Game())->setGameId(55);

        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('findByGameId')
            ->with(55)
            ->willReturn([]);

        $this->userRepository->expects(self::never())->method('findBy');

        $result = $this->service->getUsersForGame($game);

        self::assertSame([], $result);
    }

    public function testGetUsersForGameReturnsTheTrimmedSeatName(): void
    {
        $game = (new Game())->setGameId(56);
        $seat = (new GamePlayers())
            ->setPlayer($this->createUserWithId(6, 'mia', 'mia@test'))
            ->setDisplayNameSnapshot(' Mia (joined) ');

        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('findByGameId')
            ->with(56)
            ->willReturn([$seat]);

        self::assertSame([['id' => 6, 'username' => 'Mia (joined)']], $this->service->getUsersForGame($game));
    }

    public function testJoinInvitedGameAddsPlayerToGame(): void
    {
        $game = (new Game())->setGameId(50)->setStatus(GameStatus::Lobby);
        $this->gameRepository
            ->expects(self::once())
            ->method('find')
            ->with(50)
            ->willReturn($game);
        $this->seatParticipants($game, 4);
        $this->gameRepository
            ->expects(self::once())
            ->method('refreshWithSeatsForUpdate')
            ->with($game);

        $user = $this->createUserWithId(5, 'player', 'p@test');

        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('isPlayerInGameForShare')
            ->with(50, 5)
            ->willReturn(false);

        $this->playerManagementService
            ->expects(self::once())
            ->method('addPlayer')
            ->with(50, 5);

        self::assertSame(50, $this->service->joinInvitedGame(50, $user));
    }

    public function testJoinInvitedGameKeepsAnExistingSeat(): void
    {
        $game = (new Game())->setGameId(51)->setStatus(GameStatus::Started);
        $this->gameRepository->method('find')->with(51)->willReturn($game);
        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('isPlayerInGameForShare')
            ->with(51, 7)
            ->willReturn(true);
        $this->playerManagementService->expects(self::never())->method('addPlayer');

        self::assertSame(51, $this->service->joinInvitedGame(51, $this->createUserWithId(7, 'seated', 's@test')));
    }

    public function testJoinInvitedGameRejectsWhenGameAlreadyStarted(): void
    {
        $game = (new Game())->setGameId(60)->setStatus(GameStatus::Started);
        $this->gameRepository
            ->expects(self::once())
            ->method('find')
            ->with(60)
            ->willReturn($game);

        $user = $this->createUserWithId(8, 'player2', 'p2@test');

        $this->expectException(GameAlreadyStartedException::class);

        $this->service->joinInvitedGame(60, $user);
    }

    #[DataProvider('endedGameStatuses')]
    public function testJoinInvitedGameRejectsEndedGameWithoutClaimingItStarted(GameStatus $status): void
    {
        $game = (new Game())->setGameId(62)->setStatus($status);
        $this->gameRepository
            ->expects(self::once())
            ->method('find')
            ->with(62)
            ->willReturn($game);

        $user = $this->createUserWithId(9, 'player3', 'p3@test');

        $this->expectException(GameJoinNotAllowedException::class);

        $this->service->joinInvitedGame(62, $user);
    }

    /**
     * @return iterable<string, array{GameStatus}>
     */
    public static function endedGameStatuses(): iterable
    {
        yield 'finished' => [GameStatus::Finished];
        yield 'aborted' => [GameStatus::Aborted];
    }

    public function testJoinInvitedGameRejectsWhenRoomIsFull(): void
    {
        $game = (new Game())->setGameId(61)->setStatus(GameStatus::Lobby);
        $this->gameRepository
            ->expects(self::once())
            ->method('find')
            ->with(61)
            ->willReturn($game);
        $this->seatParticipants($game, 10);
        $this->gameRepository
            ->expects(self::once())
            ->method('refreshWithSeatsForUpdate')
            ->with($game);
        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('isPlayerInGameForShare')
            ->with(61, 9)
            ->willReturn(false);
        $this->playerManagementService
            ->expects(self::never())
            ->method('addPlayer');

        $user = $this->createUserWithId(9, 'player3', 'p3@test');

        $this->expectException(GameRoomFullException::class);
        $this->expectExceptionMessage('The room is already full (10 players). Please wait for an available spot.');

        $this->service->joinInvitedGame(61, $user);
    }

    public function testJoinInvitedGameRejectsAGameThatIsGone(): void
    {
        $this->gameRepository
            ->expects(self::once())
            ->method('find')
            ->with(62)
            ->willReturn(null);

        $user = $this->createUserWithId(10, 'player4', 'p4@test');

        $this->expectException(GameNotFoundException::class);

        $this->service->joinInvitedGame(62, $user);
    }

    private function createUserWithId(int $id, string $username, string $email): User
    {
        $user = (new User())
            ->setUsername($username)
            ->setEmail($email)
            ->setPassword('secret');

        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }

    private function seatParticipants(Game $game, int $count): void
    {
        for ($position = 1; $position <= $count; $position++) {
            $game->addGamePlayer((new GamePlayers())->setPosition($position));
        }
    }
}
