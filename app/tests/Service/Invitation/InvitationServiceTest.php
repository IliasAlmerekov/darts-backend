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
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\InvitationRepositoryInterface;
use App\Repository\UserRepositoryInterface;
use App\Service\Invitation\InvitationService;
use App\Service\Player\PlayerManagementServiceInterface;
use App\Service\Security\GameAccessServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\RouterInterface;

#[AllowMockObjectsWithoutExpectations]
final class InvitationServiceTest extends TestCase
{
    private InvitationRepositoryInterface&MockObject $invitationRepository;
    private GamePlayersRepositoryInterface&MockObject $gamePlayersRepository;
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
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->playerManagementService = $this->createMock(PlayerManagementServiceInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->router = $this->createMock(RouterInterface::class);
        $this->gameAccessService = $this->createMock(GameAccessServiceInterface::class);
        $this->gameAccessService->method('assertPlayerInGameOrAdmin')->willReturn(new User());

        $this->service = new InvitationService(
            $this->invitationRepository,
            $this->gamePlayersRepository,
            $this->userRepository,
            $this->playerManagementService,
            $this->entityManager,
            $this->router,
            $this->gameAccessService
        );
    }

    public function testCreateOrGetReturnsExistingInvitation(): void
    {
        $game = (new Game())->setGameId(10);
        $existing = (new Invitation())->setUuid('uuid')->setGameId(10);

        $this->invitationRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['gameId' => 10])
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
            ->with(['gameId' => 20])
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
        $invitation = (new Invitation())->setUuid('abc')->setGameId(30);

        $player = new GamePlayers();
        $user = $this->createUserWithId(101, 'u', 'u@test');
        // emulate relation
        $player->setPlayer($user);

        $this->invitationRepository
            ->method('findOneBy')
            ->with(['gameId' => 30])
            ->willReturn($invitation);

        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('findByGameId')
            ->with(30)
            ->willReturn([$player]);

        $this->userRepository
            ->expects(self::once())
            ->method('findBy')
            ->with(['id' => [$user->getId()]])
            ->willReturn([$user]);

        $this->router
            ->expects(self::once())
            ->method('generate')
            ->with('join_invitation', ['uuid' => 'abc'])
            ->willReturn('/join/abc');

        $payload = $this->service->getInvitationPayload($game);

        self::assertTrue($payload['success']);
        self::assertSame(30, $payload['gameId']);
        self::assertSame('/join/abc', $payload['invitationLink']);
        self::assertSame([$user], $payload['users']);
    }

    public function testProcessInvitationRejectsWhenUserNotLoggedIn(): void
    {
        $session = $this->createMock(SessionInterface::class);

        $response = $this->service->processInvitation($session, null);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testProcessInvitationAddsPlayerToGameAndRedirects(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())->method('get')->with('game_id')->willReturn(50);

        $removedKeys = [];
        $session->expects(self::exactly(2))
            ->method('remove')
            ->willReturnCallback(static function (string $key) use (&$removedKeys): void {
                $removedKeys[] = $key;
            });

        $user = $this->createUserWithId(5, 'player', 'p@test');

        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('isPlayerInGame')
            ->with(50, 5)
            ->willReturn(false);

        $this->playerManagementService
            ->expects(self::once())
            ->method('addPlayer')
            ->with(50, 5);

        $response = $this->service->processInvitation($session, $user);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $content = json_decode($response->getContent() ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($content['success']);
        self::assertArrayHasKey('redirect', $content);
        sort($removedKeys);
        self::assertSame(['game_id', 'invitation_uuid'], $removedKeys);
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
}
