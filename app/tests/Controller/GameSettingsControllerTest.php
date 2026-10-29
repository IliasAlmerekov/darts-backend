<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GameSettingsController;
use App\Dto\GameSettingsRequest;
use App\Dto\GameSettingsResponseDto;
use App\Entity\Game;
use App\Enum\GameStatus;
use App\Exception\Game\GameIdMissingException;
use App\Exception\Game\NoSettingsProvidedException;
use App\Service\Game\GameSettingsServiceInterface;
use App\Service\Security\GameAccessServiceInterface;
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
