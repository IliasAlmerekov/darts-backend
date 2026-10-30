<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\RematchResponseDto;
use App\Entity\Game;
use App\Entity\Invitation;
use App\Enum\GameStatus;
use App\Exception\Game\GameNotFoundException;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\GameRepositoryInterface;
use App\Service\Invitation\InvitationServiceInterface;
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
        $invitationService = $this->createMock(InvitationServiceInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $service = new RematchService(
            $playerManagementService,
            $invitationService,
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

        $invitationService = $this->createMock(InvitationServiceInterface::class);
        $invitation = (new Invitation())->setUuid('existing-uuid')->setGame($newLobby);
        $invitationService->expects(self::once())->method('createOrGetInvitation')->with($newLobby)->willReturn($invitation);
        $entityManager->expects(self::never())->method('persist');

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())->method('generate')
            ->with('join_invitation', ['uuid' => 'existing-uuid'])->willReturn('/join/existing-uuid');

        $service = new RematchService(
            $playerManagementService,
            $invitationService,
            $entityManager,
            $urlGenerator,
            $openLobbyService,
            $gameRepository,
            $lockedGame = new LockedGameTransactionFake($oldGame),
        );

        $result = $service->createRematch(42);

        self::assertSame(['runWithRetry'], $lockedGame->calls);
        self::assertEquals(new RematchResponseDto(100, '/join/existing-uuid'), $result);
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
