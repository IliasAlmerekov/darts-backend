<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\GameSettingsRequest;
use App\Entity\Game;
use App\Enum\GameStatus;
use App\Exception\Game\InvalidStartScoreException;
use App\Exception\Game\NoSettingsProvidedException;
use App\Exception\Game\SettingsNotEditableException;
use App\Exception\Game\StartScoreCannotBeChangedAfterStartException;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Game\GameSettingsService;
use App\Service\Game\LockedGameTransactionInterface;
use App\Tests\Support\LockedGameTransactionFake;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class GameSettingsServiceTest extends TestCase
{
    public function testUpdateSettingsChangesValuesAndFlushes(): void
    {
        $game = (new Game())->setGameId(1);
        $dto = new GameSettingsRequest();
        $dto->startScore = 501;
        $dto->doubleOut = true;
        $dto->tripleOut = false;

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $lockedGame = new LockedGameTransactionFake($game);
        $service = new GameSettingsService($entityManager, $this->createStub(RoundThrowsRepositoryInterface::class), $lockedGame);
        $service->updateSettings($game, $dto);

        self::assertSame(['run'], $lockedGame->calls);
        self::assertSame(501, $game->getStartScore());
        self::assertTrue($game->isDoubleOut());
        self::assertFalse($game->isTripleOut());
    }

    public function testUpdateSettingsThrowsWhenGameNotInLobby(): void
    {
        $game = (new Game())->setGameId(1);
        $game->setStatus(GameStatus::Finished);
        $dto = new GameSettingsRequest();
        $dto->startScore = 301;

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = $this->createService($entityManager, $game);

        $this->expectException(SettingsNotEditableException::class);
        $service->updateSettings($game, $dto);
    }

    public function testUpdateSettingsThrowsWhenPayloadIsEmpty(): void
    {
        $game = (new Game())->setGameId(1);
        $dto = new GameSettingsRequest();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = $this->createService($entityManager, $game);

        $this->expectException(NoSettingsProvidedException::class);
        $service->updateSettings($game, $dto);
    }

    public function testUpdateSettingsThrowsOnInvalidStartScore(): void
    {
        $game = (new Game())->setGameId(1);
        $dto = new GameSettingsRequest();
        $dto->startScore = 999;

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = $this->createService($entityManager, $game);

        $this->expectException(InvalidStartScoreException::class);
        $service->updateSettings($game, $dto);
    }

    public function testUpdateSettingsAllowsFlagsWhenStarted(): void
    {
        $game = (new Game())->setGameId(1);
        $game->setStatus(GameStatus::Started);
        $dto = new GameSettingsRequest();
        $dto->doubleOut = true;
        $dto->tripleOut = false;

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = $this->createService($entityManager, $game);
        $service->updateSettings($game, $dto);

        self::assertTrue($game->isDoubleOut());
        self::assertFalse($game->isTripleOut());
    }

    public function testUpdateSettingsBlocksStartScoreWhenStarted(): void
    {
        $game = (new Game())->setGameId(1);
        $game->setStatus(GameStatus::Started);
        $dto = new GameSettingsRequest();
        $dto->startScore = 301;

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = $this->createService($entityManager, $game);

        $this->expectException(StartScoreCannotBeChangedAfterStartException::class);
        $service->updateSettings($game, $dto);
    }

    public function testApplySettingsSkipsAccessChecksForNewRoomFlow(): void
    {
        $game = (new Game())->setGameId(1);
        $dto = new GameSettingsRequest();
        $dto->startScore = 401;
        $dto->doubleOut = true;
        $dto->tripleOut = false;

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $lockedGameTransaction = $this->createMock(LockedGameTransactionInterface::class);
        $lockedGameTransaction->expects(self::never())->method('run');

        $service = new GameSettingsService($entityManager, $this->createStub(RoundThrowsRepositoryInterface::class), $lockedGameTransaction);
        $service->applySettings($game, $dto);

        self::assertSame(401, $game->getStartScore());
        self::assertTrue($game->isDoubleOut());
        self::assertFalse($game->isTripleOut());
    }

    public function testApplySettingsNeverTakesTheGameLock(): void
    {
        $dto = new GameSettingsRequest();
        $dto->startScore = 401;

        $lockedGameTransaction = $this->createMock(LockedGameTransactionInterface::class);
        $lockedGameTransaction->expects(self::never())->method('run');
        $lockedGameTransaction->expects(self::never())->method('runWithRetry');
        $service = new GameSettingsService($this->createStub(EntityManagerInterface::class), $this->createStub(RoundThrowsRepositoryInterface::class), $lockedGameTransaction);

        $service->applySettings(new Game(), $dto);
    }

    private function createService(EntityManagerInterface $entityManager, Game $game): GameSettingsService
    {
        return new GameSettingsService($entityManager, $this->createStub(RoundThrowsRepositoryInterface::class), new LockedGameTransactionFake($game));
    }
}
