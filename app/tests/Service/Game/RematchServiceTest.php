<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Entity\User;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\GameRepositoryInterface;
use App\Repository\InvitationRepositoryInterface;
use App\Service\Game\GameRoomService;
use App\Service\Game\RematchService;
use App\Service\Player\PlayerManagementService;
use App\Service\Security\GameAccessServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AllowMockObjectsWithoutExpectations]
final class RematchServiceTest extends TestCase
{
    public function testCreateRematchReturnsErrorWhenOldGameMissing(): void
    {
        $gameRepository = $this->createMock(GameRepositoryInterface::class);
        $gameRepository->method('find')->with(42)->willReturn(null);

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $playerManagementService = new PlayerManagementService($gamePlayersRepository, $entityManager);
        $access = $this->createMock(GameAccessServiceInterface::class);
        $access->method('assertPlayerInGameOrAdmin')->willReturn(new User());
        $gameRoomService = new GameRoomService($gameRepository, $gamePlayersRepository, $entityManager, $playerManagementService, $access);
        $invitationRepository = $this->createMock(InvitationRepositoryInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $service = new RematchService(
            $gameRoomService,
            $playerManagementService,
            $invitationRepository,
            $entityManager,
            $urlGenerator,
            $access
        );

        $result = $service->createRematch(42);

        self::assertFalse($result['success']);
        self::assertSame('Previous game not found', $result['message']);
    }

    public function testCreateRematchCreatesGameCopiesPlayersAndBuildsInvitation(): void
    {
        $oldGame = new Game();
        $this->setPrivateProperty($oldGame, 'gameId', 42);

        $gameRepository = $this->createMock(GameRepositoryInterface::class);
        $gameRepository->method('find')->with(42)->willReturn($oldGame);

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->expects(self::once())
            ->method('findByGameId')
            ->with(42)
            ->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof Game && null === $entity->getGameId()) {
                $this->setPrivateProperty($entity, 'gameId', 100);
            }
        });
        $entityManager->expects(self::atLeastOnce())->method('flush');

        $playerManagementService = new PlayerManagementService($gamePlayersRepository, $entityManager);
        $access = $this->createMock(GameAccessServiceInterface::class);
        $access->method('requireAuthenticatedUser')->willReturn(new User());
        $access->method('assertPlayerInGameOrAdmin')->willReturn(new User());
        $gameRoomService = new GameRoomService($gameRepository, $gamePlayersRepository, $entityManager, $playerManagementService, $access);

        $invitationRepository = $this->createMock(InvitationRepositoryInterface::class);
        $invitationRepository->method('findOneBy')->willReturn(null);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/join/uuid');

        $service = new RematchService(
            $gameRoomService,
            $playerManagementService,
            $invitationRepository,
            $entityManager,
            $urlGenerator,
            $access
        );

        $result = $service->createRematch(42);

        self::assertTrue($result['success']);
        self::assertSame(100, $result['gameId']);
        self::assertSame('/join/uuid', $result['invitationLink']);
        self::assertArrayNotHasKey('finishedPlayers', $result);
        self::assertArrayNotHasKey('winner', $result);
        self::assertArrayNotHasKey('winnerRoundsPlayed', $result);
    }

    /**
     * @throws \ReflectionException
     */
    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setValue($object, $value);
    }
}
