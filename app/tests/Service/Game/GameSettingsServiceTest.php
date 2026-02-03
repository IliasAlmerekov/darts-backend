<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\GameSettingsRequest;
use App\Entity\Game;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Exception\Game\InvalidStartScoreException;
use App\Exception\Game\NoSettingsProvidedException;
use App\Exception\Game\SettingsNotEditableException;
use App\Exception\Game\StartScoreCannotBeChangedAfterStartException;
use App\Service\Game\GameSettingsService;
use App\Service\Security\GameAccessServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class GameSettingsServiceTest extends TestCase
{
    public function testUpdateSettingsChangesValuesAndFlushes(): void
    {
        $game = new Game();
        $dto = new GameSettingsRequest();
        $dto->startScore = 501;
        $dto->doubleOut = true;
        $dto->tripleOut = false;

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = $this->createService($entityManager);
        $service->updateSettings($game, $dto);

        self::assertSame(501, $game->getStartScore());
        self::assertTrue($game->isDoubleOut());
        self::assertFalse($game->isTripleOut());
    }

    public function testUpdateSettingsThrowsWhenGameNotInLobby(): void
    {
        $game = new Game();
        $game->setStatus(GameStatus::Finished);
        $dto = new GameSettingsRequest();
        $dto->startScore = 301;

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = $this->createService($entityManager);

        $this->expectException(SettingsNotEditableException::class);
        $service->updateSettings($game, $dto);
    }

    public function testUpdateSettingsThrowsWhenPayloadIsEmpty(): void
    {
        $game = new Game();
        $dto = new GameSettingsRequest();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = $this->createService($entityManager);

        $this->expectException(NoSettingsProvidedException::class);
        $service->updateSettings($game, $dto);
    }

    public function testUpdateSettingsThrowsOnInvalidStartScore(): void
    {
        $game = new Game();
        $dto = new GameSettingsRequest();
        $dto->startScore = 999;

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = $this->createService($entityManager);

        $this->expectException(InvalidStartScoreException::class);
        $service->updateSettings($game, $dto);
    }

    public function testUpdateSettingsAllowsFlagsWhenStarted(): void
    {
        $game = new Game();
        $game->setStatus(GameStatus::Started);
        $dto = new GameSettingsRequest();
        $dto->doubleOut = true;
        $dto->tripleOut = true;

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = $this->createService($entityManager);
        $service->updateSettings($game, $dto);

        self::assertTrue($game->isDoubleOut());
        self::assertTrue($game->isTripleOut());
    }

    public function testUpdateSettingsBlocksStartScoreWhenStarted(): void
    {
        $game = new Game();
        $game->setStatus(GameStatus::Started);
        $dto = new GameSettingsRequest();
        $dto->startScore = 301;

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = $this->createService($entityManager);

        $this->expectException(StartScoreCannotBeChangedAfterStartException::class);
        $service->updateSettings($game, $dto);
    }

    private function createService(EntityManagerInterface $entityManager): GameSettingsService
    {
        $access = $this->createStub(GameAccessServiceInterface::class);
        $access->method('assertPlayerInGameOrAdmin')->willReturn(new User());

        return new GameSettingsService($entityManager, $access);
    }
}
