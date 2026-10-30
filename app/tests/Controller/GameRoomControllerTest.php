<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GameRoomController;
use App\Dto\RematchResponseDto;
use App\Dto\RoomCreateResponseDto;
use App\Entity\Game;
use App\Dto\RoomCreateRequest;
use App\Service\Game\GameRoomServiceInterface;
use App\Service\Player\PlayerManagementServiceInterface;
use App\Service\Player\GuestPlayerServiceInterface;
use App\Service\Game\RematchServiceInterface;
use App\Service\Sse\SseStreamServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[AllowMockObjectsWithoutExpectations]
class GameRoomControllerTest extends TestCase
{
    private GameRoomServiceInterface&MockObject $gameRoomService;
    private RematchServiceInterface&MockObject $rematchService;
    private GameRoomController $controller;

    protected function setUp(): void
    {
        $this->gameRoomService = $this->createMock(GameRoomServiceInterface::class);
        $this->rematchService = $this->createMock(RematchServiceInterface::class);

        $this->controller = new GameRoomController(
            $this->gameRoomService,
            $this->createMock(PlayerManagementServiceInterface::class),
            $this->rematchService,
            $this->createMock(GuestPlayerServiceInterface::class),
            $this->createMock(SseStreamServiceInterface::class),
        );
        $this->controller->setContainer($this->createMock(ContainerInterface::class));
    }

    public function testRoomCreatePassesTheRequestToOpenRoomAndReturnsTheLobbyId(): void
    {
        $dto = new RoomCreateRequest(
            previousGameId: 12,
            playerIds: [1, 2],
            excludePlayerIds: [2],
            startScore: 501,
            doubleOut: true,
            tripleOut: false,
        );
        $game = (new Game())->setGameId(123);

        $this->gameRoomService->expects($this->once())
            ->method('openRoom')
            ->with($this->identicalTo($dto))
            ->willReturn($game);

        $response = $this->controller->roomCreateApi($dto);

        $this->assertEquals(new RoomCreateResponseDto(123), $response);
    }

    public function testRematchReturnsTheServiceResult(): void
    {
        $result = new RematchResponseDto(99, '/join/uuid');

        $this->rematchService->expects($this->once())
            ->method('createRematch')
            ->with(42)
            ->willReturn($result);

        $this->assertSame($result, $this->controller->rematch(42));
    }
}
