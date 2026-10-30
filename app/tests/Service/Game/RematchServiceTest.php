<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Enum\GameStatus;
use App\Exception\Game\GameNotFoundException;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\GameRepositoryInterface;
use App\Repository\InvitationRepositoryInterface;
use App\Service\Game\OpenLobby;
use App\Service\Game\OpenLobbyServiceInterface;
use App\Service\Game\RematchService;
use App\Service\Player\PlayerManagementService;
use App\Tests\Support\LockedGameTransactionFake;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AllowMockObjectsWithoutExpectations]
final class RematchServiceTest extends TestCase
{
    public function testCreateRematchThrowsGameNotFoundWhenOldGameMissing(): void
    {
        $gameRepository = $this->createMock(GameRepositoryInterface::class);

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $playerManagementService = new PlayerManagementService($gamePlayersRepository, $entityManager, $gameRepository);
        $openLobbyService = $this->createMock(OpenLobbyServiceInterface::class);
        $openLobbyService->expects(self::never())->method('findOrCreate');
        $invitationRepository = $this->createMock(InvitationRepositoryInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $service = new RematchService(
            $playerManagementService,
            $invitationRepository,
            $entityManager,
            $urlGenerator,
            $openLobbyService,
            $gameRepository,
            new LockedGameTransactionFake(null),
        );

        $this->expectException(GameNotFoundException::class);
        $service->createRematch(42);
    }

    public function testCreateRematchCreatesGameCopiesPlayersAndBuildsInvitation(): void
    {
        $oldGame = (new Game())->setStatus(GameStatus::Finished);
        $this->setPrivateProperty($oldGame, 'gameId', 42);

        $gameRepository = $this->createMock(GameRepositoryInterface::class);
        $gameRepository->method('findHighestGameId')->willReturn(42);

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->expects(self::once())
            ->method('findByGameId')
            ->with(42)
            ->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::atLeastOnce())->method('flush');

        $playerManagementService = new PlayerManagementService($gamePlayersRepository, $entityManager, $gameRepository);
        $newLobby = new Game();
        $this->setPrivateProperty($newLobby, 'gameId', 100);
        $openLobbyService = $this->createMock(OpenLobbyServiceInterface::class);
        $openLobbyService->method('findOrCreate')->willReturn(new OpenLobby($newLobby, true));

        $invitationRepository = $this->createMock(InvitationRepositoryInterface::class);
        $invitationRepository->method('findOneByGameIdForShare')->willReturn(null);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/join/uuid');

        $service = new RematchService(
            $playerManagementService,
            $invitationRepository,
            $entityManager,
            $urlGenerator,
            $openLobbyService,
            $gameRepository,
            $lockedGame = new LockedGameTransactionFake($oldGame),
        );

        $result = $service->createRematch(42);

        self::assertSame(['runWithRetry'], $lockedGame->calls);
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
