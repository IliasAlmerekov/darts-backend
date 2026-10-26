<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Exception\Game\GameReopenNotAllowedException;
use App\Repository\GameRepositoryInterface;
use App\Service\Game\GameReopenService;
use App\Service\Game\NewestGameGuard;
use App\Service\Security\GameAccessServiceInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class GameReopenServiceTest extends TestCase
{
    public function testReopenKeepsPlacementAndWinWhenTheWinnerHasCheckedOut(): void
    {
        $winner = (new User())->setUsername('winner');
        $finishedPlayer = (new GamePlayers())
            ->setPlayer($winner)
            ->setScore(0)
            ->setPosition(2)
            ->setPlacement(1)
            ->setIsWinner(true);
        $activePlayer = (new GamePlayers())
            ->setPlayer((new User())->setUsername('active'))
            ->setScore(20)
            ->setPosition(1)
            ->setPlacement(2)
            ->setIsWinner(false);

        $game = (new Game())
            ->setGameId(7)
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

        $gameRepository = $this->createMock(GameRepositoryInterface::class);
        $gameRepository->expects(self::once())->method('refreshWithSeatsForUpdate')->with($game);
        $gameRepository->expects(self::once())->method('abortStartedGames')->with($game->getGameId());
        $gameRepository->method('findHighestGameId')->willReturn(7);

        $service = new GameReopenService($entityManager, $access, $gameRepository, new NewestGameGuard($gameRepository));

        $service->reopen($game);

        self::assertSame(GameStatus::Started, $game->getStatus());
        self::assertNull($game->getFinishedAt());
        self::assertSame($winner, $game->getWinner());
        self::assertTrue($finishedPlayer->isWinner());
        self::assertFalse((bool) $activePlayer->isWinner());
        self::assertSame(1, $finishedPlayer->getPlacement());
        self::assertNull($activePlayer->getPlacement());
        // The positions are the turn order and stay as they were.
        self::assertSame(2, $finishedPlayer->getPosition());
        self::assertSame(1, $activePlayer->getPosition());
    }

    public function testReopenClearsWinAndPlacementsOfSeatsWithScoreLeft(): void
    {
        $winner = (new User())->setUsername('winner');
        $winningSeat = (new GamePlayers())->setPlayer($winner)->setScore(20)->setPosition(1)->setPlacement(1)->setIsWinner(true);
        $otherSeat = (new GamePlayers())->setPlayer((new User())->setUsername('other'))->setScore(90)->setPosition(2)->setPlacement(2)->setIsWinner(false);
        $game = (new Game())
            ->setGameId(7)
            ->setStatus(GameStatus::Finished)
            ->setFinishedAt(new DateTimeImmutable())
            ->setWinner($winner)
            ->addGamePlayer($winningSeat)
            ->addGamePlayer($otherSeat);

        $access = $this->createMock(GameAccessServiceInterface::class);
        $access->method('assertPlayerInGameOrAdmin')->willReturn(new User());
        $gameRepository = $this->createMock(GameRepositoryInterface::class);
        $gameRepository->method('findHighestGameId')->willReturn(7);
        $service = new GameReopenService($this->createMock(EntityManagerInterface::class), $access, $gameRepository, new NewestGameGuard($gameRepository));

        $service->reopen($game);

        self::assertNull($game->getWinner());
        self::assertFalse((bool) $winningSeat->isWinner());
        self::assertNull($winningSeat->getPlacement());
        self::assertNull($otherSeat->getPlacement());
        self::assertSame([1, 2], [$winningSeat->getPosition(), $otherSeat->getPosition()]);
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

        $gameRepository = $this->createMock(GameRepositoryInterface::class);
        $gameRepository->expects(self::never())->method('abortStartedGames');

        $service = new GameReopenService($entityManager, $access, $gameRepository, new NewestGameGuard($gameRepository));

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

        $gameRepository = $this->createStub(GameRepositoryInterface::class);
        $service = new GameReopenService($entityManager, $access, $gameRepository, new NewestGameGuard($gameRepository));

        $this->expectException(GameReopenNotAllowedException::class);
        $service->reopen($game);
    }
}
