<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\GameRepositoryInterface;
use App\Repository\InvitationRepositoryInterface;
use App\Repository\RoundRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Game\GameFinishService;
use App\Service\Game\GameRoomService;
use App\Service\Player\PlayerManagementService;
use App\Service\Game\RematchService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class RematchServiceTest extends TestCase
{
    public function testCreateRematchReturnsErrorWhenOldGameMissing(): void
    {
        $gameRepository = $this->createMock(GameRepositoryInterface::class);
        $gameRepository->method('find')->with(42)->willReturn(null);

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $playerManagementService = new PlayerManagementService($gamePlayersRepository, $entityManager);
        $gameRoomService = new GameRoomService($gameRepository, $gamePlayersRepository, $entityManager, $playerManagementService);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $gameFinishService = new GameFinishService($entityManager, $gamePlayersRepository, $roundThrowsRepository, $roundRepository);
        $invitationRepository = $this->createMock(InvitationRepositoryInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $service = new RematchService(
            $gameRoomService,
            $playerManagementService,
            $gameFinishService,
            $invitationRepository,
            $entityManager,
            $urlGenerator
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
        $gamePlayersRepository->method('findByGameId')->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof Game && null === $entity->getGameId()) {
                $this->setPrivateProperty($entity, 'gameId', 100);
            }
        });
        $entityManager->expects(self::atLeastOnce())->method('flush');

        $playerManagementService = new PlayerManagementService($gamePlayersRepository, $entityManager);
        $gameRoomService = new GameRoomService($gameRepository, $gamePlayersRepository, $entityManager, $playerManagementService);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->method('countFinishedRounds')->willReturn(0);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('getRoundsPlayedForGame')->willReturn([]);
        $roundThrowsRepository->method('getTotalScoreForGame')->willReturn([]);

        $gameFinishService = new GameFinishService(
            $entityManager,
            $gamePlayersRepository,
            $roundThrowsRepository,
            $roundRepository
        );

        $invitationRepository = $this->createMock(InvitationRepositoryInterface::class);
        $invitationRepository->method('findOneBy')->willReturn(null);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/join/uuid');

        $service = new RematchService(
            $gameRoomService,
            $playerManagementService,
            $gameFinishService,
            $invitationRepository,
            $entityManager,
            $urlGenerator
        );

        $result = $service->createRematch(42);

        self::assertTrue($result['success']);
        self::assertSame(100, $result['gameId']);
        self::assertSame('/join/uuid', $result['invitationLink']);
        self::assertIsArray($result['finishedPlayers']);
    }

    /**
     * @throws \ReflectionException
     */
    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setAccessible(true);
        $ref->setValue($object, $value);
    }
}
