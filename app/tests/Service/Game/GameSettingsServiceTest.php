<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\GameSettingsRequest;
use App\Entity\Game;
use App\Enum\GameStatus;
use App\Service\Game\GameSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
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

        $service = new GameSettingsService($entityManager);
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

        $service = new GameSettingsService($entityManager);

        $this->expectException(InvalidArgumentException::class);
        $service->updateSettings($game, $dto);
    }

    public function testUpdateSettingsThrowsWhenPayloadIsEmpty(): void
    {
        $game = new Game();
        $dto = new GameSettingsRequest();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = new GameSettingsService($entityManager);

        $this->expectException(InvalidArgumentException::class);
        $service->updateSettings($game, $dto);
    }

    public function testUpdateSettingsThrowsOnInvalidStartScore(): void
    {
        $game = new Game();
        $dto = new GameSettingsRequest();
        $dto->startScore = 999;

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = new GameSettingsService($entityManager);

        $this->expectException(InvalidArgumentException::class);
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

        $service = new GameSettingsService($entityManager);
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

        $service = new GameSettingsService($entityManager);

        $this->expectException(InvalidArgumentException::class);
        $service->updateSettings($game, $dto);
    }
}
