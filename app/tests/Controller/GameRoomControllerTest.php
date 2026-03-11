<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GameRoomController;
use App\Dto\GameSettingsRequest;
use App\Entity\Game;
use App\Dto\RoomCreateRequest;
use App\Service\Game\GameRoomServiceInterface;
use App\Service\Game\GameSettingsServiceInterface;
use App\Service\Player\PlayerManagementServiceInterface;
use App\Service\Player\GuestPlayerServiceInterface;
use App\Service\Game\RematchServiceInterface;
use App\Service\Sse\SseStreamServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

#[AllowMockObjectsWithoutExpectations]
class GameRoomControllerTest extends TestCase
{
    private GameRoomServiceInterface&MockObject $gameRoomService;
    private PlayerManagementServiceInterface&MockObject $playerManagementService;
    private GameSettingsServiceInterface&MockObject $gameSettingsService;
    private RematchServiceInterface&MockObject $rematchService;
    private GuestPlayerServiceInterface&MockObject $guestPlayerService;
    private SseStreamServiceInterface&MockObject $sseStreamService;
    private EntityManagerInterface&MockObject $entityManager;
    private GameRoomController $controller;
    private ContainerInterface&MockObject $container;

    protected function setUp(): void
    {
        // Mock aller Services
        $this->gameRoomService = $this->createMock(GameRoomServiceInterface::class);
        $this->playerManagementService = $this->createMock(PlayerManagementServiceInterface::class);
        $this->gameSettingsService = $this->createMock(GameSettingsServiceInterface::class);
        $this->rematchService = $this->createMock(RematchServiceInterface::class);
        $this->guestPlayerService = $this->createMock(GuestPlayerServiceInterface::class);
        $this->sseStreamService = $this->createMock(SseStreamServiceInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        // Controller mit allen Dependencies instanziieren
        $this->controller = new GameRoomController(
            $this->gameRoomService,
            $this->playerManagementService,
            $this->gameSettingsService,
            $this->rematchService,
            $this->guestPlayerService,
            $this->sseStreamService,
            $this->entityManager,
        );

        // Container für AbstractController-Methoden
        $this->container = $this->createMock(ContainerInterface::class);
        $this->controller->setContainer($this->container);
    }

    /**
     * Test: POST Request ohne Parameter -> Game wird erstellt
     */
    public function testRoomCreatePostCreatesGame(): void
    {
        $gameMock = $this->createMock(Game::class);
        $gameMock->method('getGameId')->willReturn(123);

        $this->gameRoomService->expects($this->once())
            ->method('createGameWithPreviousPlayers')
            ->with(null, null, null)
            ->willReturn($gameMock);
        $this->gameSettingsService->expects($this->never())->method('applySettings');
        $this->entityManager->expects($this->once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn(callable $callback) => $callback());

        $this->container->method('has')->willReturn(false);

        $dto = new RoomCreateRequest();

        $response = $this->controller->roomCreateApi($dto);

        $this->assertIsArray($response);
    }

    public function testRoomCreateAppliesProvidedSettingsToNewGame(): void
    {
        $gameMock = $this->createMock(Game::class);
        $gameMock->method('getGameId')->willReturn(123);

        $this->gameRoomService->expects($this->once())
            ->method('createGameWithPreviousPlayers')
            ->with(12, [1, 2], [2])
            ->willReturn($gameMock);
        $this->gameSettingsService->expects($this->once())
            ->method('applySettings')
            ->with(
                $gameMock,
                $this->callback(static function (GameSettingsRequest $dto): bool {
                    return 501 === $dto->startScore && true === $dto->doubleOut && false === $dto->tripleOut;
                })
            );
        $this->entityManager->expects($this->once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn(callable $callback) => $callback());

        $dto = new RoomCreateRequest(
            previousGameId: 12,
            playerIds: [1, 2],
            excludePlayerIds: [2],
            startScore: 501,
            doubleOut: true,
            tripleOut: false,
        );

        $response = $this->controller->roomCreateApi($dto);

        $this->assertSame(['success' => true, 'gameId' => 123], $response);
    }
    public function testRematchReturnsSuccess(): void
    {
        $gameId = 42;
        $expectedResult = [
            'success' => true,
            'gameId' => 99,
            'invitationLink' => '/join/uuid',
        ];

        $this->rematchService->expects($this->once())
            ->method('createRematch')
            ->with($gameId)
            ->willReturn($expectedResult);

        $response = $this->controller->rematch($gameId);

        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertEquals(99, $response['gameId']);
        $this->assertSame('/join/uuid', $response['invitationLink']);
        $this->assertArrayNotHasKey('finishedPlayers', $response);
        $this->assertArrayNotHasKey('winner', $response);
        $this->assertArrayNotHasKey('winnerRoundsPlayed', $response);
    }
}
