<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Exception\Game\GameReopenNotAllowedException;
use App\Service\Game\GameReopenService;
use App\Service\Security\GameAccessServiceInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class GameReopenServiceTest extends TestCase
{
    public function testReopenFinishedGameResetsCompletionState(): void
    {
        $winner = (new User())->setUsername('winner');
        $finishedPlayer = (new GamePlayers())
            ->setPlayer($winner)
            ->setScore(0)
            ->setPosition(1)
            ->setIsWinner(true);
        $activePlayer = (new GamePlayers())
            ->setPlayer((new User())->setUsername('active'))
            ->setScore(20)
            ->setPosition(2)
            ->setIsWinner(false);

        $game = (new Game())
            ->setStatus(GameStatus::Finished)
            ->setFinishedAt(new DateTimeImmutable())
            ->setWinner($winner)
            ->addGamePlayer($finishedPlayer)
            ->addGamePlayer($activePlayer);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $access = $this->createMock(GameAccessServiceInterface::class);
        $access->expects(self::once())
            ->method('assertPlayerInGameOrAdmin')
            ->with($game)
            ->willReturn(new User());

        $service = new GameReopenService($entityManager, $access);

        $service->reopen($game);

        self::assertSame(GameStatus::Started, $game->getStatus());
        self::assertNull($game->getFinishedAt());
        self::assertNull($game->getWinner());
        self::assertFalse((bool) $finishedPlayer->isWinner());
        self::assertFalse((bool) $activePlayer->isWinner());
    }

    public function testReopenStartedGameIsNoop(): void
    {
        $game = (new Game())
            ->setStatus(GameStatus::Started);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $access = $this->createMock(GameAccessServiceInterface::class);
        $access->expects(self::once())
            ->method('assertPlayerInGameOrAdmin')
            ->with($game)
            ->willReturn(new User());

        $service = new GameReopenService($entityManager, $access);

        $service->reopen($game);

        self::assertSame(GameStatus::Started, $game->getStatus());
    }

    public function testReopenThrowsForLobbyGame(): void
    {
        $game = (new Game())
            ->setStatus(GameStatus::Lobby);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $access = $this->createMock(GameAccessServiceInterface::class);
        $access->expects(self::once())
            ->method('assertPlayerInGameOrAdmin')
            ->with($game)
            ->willReturn(new User());

        $service = new GameReopenService($entityManager, $access);

        $this->expectException(GameReopenNotAllowedException::class);
        $service->reopen($game);
    }
}
