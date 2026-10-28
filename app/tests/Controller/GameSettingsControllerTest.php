<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GameSettingsController;
use App\Dto\GameResponseDto;
use App\Dto\GameSettingsRequest;
use App\Dto\GameSettingsResponseDto;
use App\Entity\Game;
use App\Enum\GameStatus;
use App\Exception\Game\GameIdMissingException;
use App\Exception\Game\NoSettingsProvidedException;
use App\Service\Game\GameRoomServiceInterface;
use App\Service\Game\GameSettingsServiceInterface;
use App\Service\Game\GameStateViewBuilderInterface;
use App\Service\Persistence\RetryingTransaction;
use App\Service\Security\GameAccessServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use OpenApi\Attributes as OA;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class GameSettingsControllerTest extends TestCase
{
    private GameSettingsController $controller;

    protected function setUp(): void
    {
        $this->controller = new GameSettingsController();
    }

    public function testCreateSettingsCreatesGame(): void
    {
        $dto = new GameSettingsRequest();
        $game = $this->createMock(Game::class);

        $roomService = $this->createMock(GameRoomServiceInterface::class);
        $roomService->method('openRoom')->willReturn($game);

        $settingsService = $this->createMock(GameSettingsServiceInterface::class);
        $settingsService->expects($this->once())->method('updateSettings')->with($game, $dto);

        $gameStateViewBuilder = $this->createMock(GameStateViewBuilderInterface::class);
        $gameStateViewBuilder->method('createGameDto')->willReturn(new GameResponseDto(id: 1, status: 'lobby', currentRound: 1, activePlayerId: null, currentThrowCount: 0, players: [], winnerId: null, settings: []));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn(callable $func) => $func());

        $response = $this->controller->createSettings($roomService, $settingsService, $gameStateViewBuilder, new RetryingTransaction($entityManager, $this->createStub(ManagerRegistry::class)), $dto);

        $this->assertInstanceOf(GameResponseDto::class, $response);
    }

    public function testCreateSettingsIsDocumentedAsDeprecatedLegacyFlow(): void
    {
        $operation = (new \ReflectionMethod(GameSettingsController::class, 'createSettings'))
            ->getAttributes(OA\Post::class)[0]
            ->newInstance();

        $this->assertTrue($operation->deprecated);
        $this->assertStringContainsString('Deprecated.', (string) $operation->description);
        $this->assertStringContainsString('/api/room/create', (string) $operation->description);
        $this->assertStringContainsString('/api/game/{gameId}/settings', (string) $operation->description);
    }

    public function testUpdateSettingsReturnsBadRequestOnError(): void
    {
        $dto = new GameSettingsRequest();
        $game = $this->createMock(Game::class);
        $settingsService = $this->createMock(GameSettingsServiceInterface::class);
        $settingsService->method('updateSettings')->willThrowException(new NoSettingsProvidedException());

        $this->expectException(NoSettingsProvidedException::class);
        $this->controller->updateSettings($game, $settingsService, $dto);
    }

    public function testUpdateSettingsReturnsLightweightSettingsPayload(): void
    {
        $dto = new GameSettingsRequest();
        $dto->startScore = 501;
        $dto->doubleOut = true;

        $game = $this->createMock(Game::class);
        $game->expects($this->once())
            ->method('getGameId')
            ->willReturn(55);
        $game->expects($this->once())
            ->method('getStartScore')
            ->willReturn(501);
        $game->expects($this->once())
            ->method('isDoubleOut')
            ->willReturn(true);
        $game->expects($this->once())
            ->method('isTripleOut')
            ->willReturn(false);
        $game->expects($this->once())
            ->method('getStatus')
            ->willReturn(GameStatus::Lobby);

        $settingsService = $this->createMock(GameSettingsServiceInterface::class);
        $settingsService->expects($this->once())
            ->method('updateSettings')
            ->with($game, $dto);

        $response = $this->controller->updateSettings($game, $settingsService, $dto);

        $this->assertInstanceOf(GameSettingsResponseDto::class, $response);
        $this->assertSame(55, $response->gameId);
        $this->assertSame(501, $response->startScore);
        $this->assertTrue($response->doubleOut);
        $this->assertFalse($response->tripleOut);
        $this->assertSame('lobby', $response->status);
    }

    public function testGetSettingsReturnsLightweightSettingsPayload(): void
    {
        $game = $this->createMock(Game::class);
        $game->expects($this->once())
            ->method('getGameId')
            ->willReturn(55);
        $game->expects($this->once())
            ->method('getStartScore')
            ->willReturn(301);
        $game->expects($this->once())
            ->method('isDoubleOut')
            ->willReturn(true);
        $game->expects($this->once())
            ->method('isTripleOut')
            ->willReturn(false);
        $game->expects($this->once())
            ->method('getStatus')
            ->willReturn(GameStatus::Started);

        $gameAccessService = $this->createMock(GameAccessServiceInterface::class);
        $gameAccessService->expects($this->once())
            ->method('assertPlayerInGameOrAdmin')
            ->with($game);

        $response = $this->controller->getSettings($game, $gameAccessService);

        $this->assertInstanceOf(GameSettingsResponseDto::class, $response);
        $this->assertSame(55, $response->gameId);
        $this->assertSame(301, $response->startScore);
        $this->assertTrue($response->doubleOut);
        $this->assertFalse($response->tripleOut);
        $this->assertSame('started', $response->status);
    }

    public function testCompactSettingsOperationsAreDocumentedAsPreferredFlow(): void
    {
        $updateOperation = (new \ReflectionMethod(GameSettingsController::class, 'updateSettings'))
            ->getAttributes(OA\Patch::class)[0]
            ->newInstance();
        $readOperation = (new \ReflectionMethod(GameSettingsController::class, 'getSettings'))
            ->getAttributes(OA\Get::class)[0]
            ->newInstance();

        $this->assertStringContainsString('Bevorzugter Settings-Flow', (string) $updateOperation->description);
        $this->assertStringContainsString('GameSettingsResponseDto', (string) $updateOperation->description);
        $this->assertStringContainsString('Bevorzugter Read-Flow', (string) $readOperation->description);
        $this->assertStringContainsString('vollständigen Spielzustand', (string) $readOperation->description);
    }

    public function testGetSettingsThrowsWhenGameIdIsMissing(): void
    {
        $game = $this->createMock(Game::class);
        $game->expects($this->once())
            ->method('getGameId')
            ->willReturn(null);

        $gameAccessService = $this->createMock(GameAccessServiceInterface::class);
        $gameAccessService->expects($this->once())
            ->method('assertPlayerInGameOrAdmin')
            ->with($game);

        $this->expectException(GameIdMissingException::class);
        $this->controller->getSettings($game, $gameAccessService);
    }
}
