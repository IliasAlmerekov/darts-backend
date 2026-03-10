<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\GameSummaryResponseDto;
use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\RoundRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Game\GameFinishService;
use App\Service\Security\GameAccessServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionProperty;

#[AllowMockObjectsWithoutExpectations]
final class GameFinishServiceTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    public function testFinishGameSetsWinnerPositionsAndAggregates(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 42);
        $game->setStatus(GameStatus::Started);

        $user1 = (new User())->setUsername('Player 1');
        $this->setPrivateProperty($user1, 'id', 1);
        $player1 = (new GamePlayers())
            ->setPlayer($user1)
            ->setScore(0); // already finished

        $user2 = (new User())->setUsername('Player 2');
        $this->setPrivateProperty($user2, 'id', 2);
        $player2 = (new GamePlayers())
            ->setPlayer($user2)
            ->setScore(10);

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findByGameId')
            ->with(42)
            ->willReturn([$player1, $player2]);
        $gamePlayersRepository->method('countFinishedPlayers')
            ->with(42)
            ->willReturn(1);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('getRoundsPlayedForGame')
            ->with(42)
            ->willReturn([1 => 5, 2 => 5]);
        $roundThrowsRepository->method('getTotalScoreForGame')
            ->with(42)
            ->willReturn([1 => 301.0, 2 => 291.0]);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->method('countFinishedRounds')
            ->with(42)
            ->willReturn(5);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = $this->createService($entityManager, $gamePlayersRepository, $roundThrowsRepository, $roundRepository);

        $summary = $service->finishGame($game);

        self::assertSame(GameStatus::Finished, $game->getStatus());
        self::assertNotNull($game->getFinishedAt());
        self::assertSame(1, $player1->getPosition());
        self::assertSame(2, $player2->getPosition());
        self::assertSame($user1, $game->getWinner());
        self::assertTrue($player1->isWinner());
        self::assertFalse($player2->isWinner());

        self::assertInstanceOf(GameSummaryResponseDto::class, $summary);
        self::assertSame(42, $summary->gameId);
        self::assertNotNull($summary->finishedAt);
        self::assertSame(1, $summary->winner?->id);
        self::assertSame('Player 1', $summary->winner?->username);
        self::assertSame(5, $summary->winnerRoundsPlayed);
        self::assertSame(301.0 / 5.0, $summary->winnerRoundAverage);

        $firstFinished = $summary->finishedPlayers[0];
        self::assertSame(1, $firstFinished->playerId);
        self::assertSame('Player 1', $firstFinished->username);
        self::assertSame(1, $firstFinished->position);
        self::assertSame(5, $firstFinished->roundsPlayed);
        self::assertSame(301.0 / 5.0, $firstFinished->roundAverage);
    }

    /**
     * @throws ReflectionException
     */
    public function testRoundsPerPlayerReflectEarlyWinnerAndLaterFinisher(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 99);
        $winnerUser = new User();
        $this->setPrivateProperty($winnerUser, 'id', 1);
        $winner = (new GamePlayers())
            ->setPlayer($winnerUser)
            ->setPosition(1)
            ->setIsWinner(true)
            ->setScore(0);

        $secondUser = new User();
        $this->setPrivateProperty($secondUser, 'id', 2);
        $second = (new GamePlayers())
            ->setPlayer($secondUser)
            ->setPosition(2)
            ->setIsWinner(false)
            ->setScore(10);

        $thirdUser = new User();
        $this->setPrivateProperty($thirdUser, 'id', 3);
        $third = (new GamePlayers())
            ->setPlayer($thirdUser)
            ->setPosition(3)
            ->setIsWinner(false)
            ->setScore(50);

        $players = [$winner, $second, $third];
        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findByGameId')
            ->with(99)
            ->willReturn($players);
        $gamePlayersRepository->method('countFinishedPlayers')
            ->willReturn(1);

        $roundsPlayedMap = [
            1 => 5,  // winner finished on round 5
            2 => 10, // finalist finished on round 10
            3 => 10, // lost to finalist on round 10 -> should match 10
        ];
        $totalsMap = [
            1 => 301.0,
            2 => 301.0,
            3 => 300.0,
        ];

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('getRoundsPlayedForGame')
            ->with(99)
            ->willReturn($roundsPlayedMap);
        $roundThrowsRepository->method('getTotalScoreForGame')
            ->with(99)
            ->willReturn($totalsMap);
        $roundThrowsRepository->method('getLastRoundNumberForGame')
            ->with(99)
            ->willReturn([
                1 => 5,
                2 => 10,
                3 => 10,
            ]);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->method('countFinishedRounds')
            ->with(99)
            ->willReturn(10);

        $entityManager = $this->createMock(EntityManagerInterface::class);

        $service = $this->createService($entityManager, $gamePlayersRepository, $roundThrowsRepository, $roundRepository);

        $summary = $service->getGameStats($game);

        self::assertInstanceOf(GameSummaryResponseDto::class, $summary);
        self::assertSame(5, $summary->winnerRoundsPlayed);
        self::assertSame(3, count($summary->finishedPlayers));
        self::assertSame(5, $summary->finishedPlayers[0]->roundsPlayed);
        self::assertSame(10, $summary->finishedPlayers[1]->roundsPlayed);
        self::assertSame(10, $summary->finishedPlayers[2]->roundsPlayed);
    }

    /**
     * @throws ReflectionException
     */
    public function testReadOnlySummaryMatchesFinishSummary(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 77);
        $game->setStatus(GameStatus::Started);

        $winnerUser = (new User())->setUsername('Winner');
        $this->setPrivateProperty($winnerUser, 'id', 1);
        $winner = (new GamePlayers())
            ->setPlayer($winnerUser)
            ->setScore(0);

        $secondUser = (new User())->setUsername('Runner up');
        $this->setPrivateProperty($secondUser, 'id', 2);
        $second = (new GamePlayers())
            ->setPlayer($secondUser)
            ->setScore(24);

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findByGameId')
            ->with(77)
            ->willReturn([$winner, $second]);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('getRoundsPlayedForGame')
            ->with(77)
            ->willReturn([1 => 6, 2 => 6]);
        $roundThrowsRepository->method('getTotalScoreForGame')
            ->with(77)
            ->willReturn([1 => 301.0, 2 => 277.0]);
        $roundThrowsRepository->method('getLastRoundNumberForGame')
            ->with(77)
            ->willReturn([1 => 6, 2 => 6]);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->method('countFinishedRounds')
            ->with(77)
            ->willReturn(6);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = $this->createService($entityManager, $gamePlayersRepository, $roundThrowsRepository, $roundRepository);
        $finishedAt = new \DateTimeImmutable('2026-03-10T12:00:00+00:00');

        $finishSummary = $service->finishGame($game, $finishedAt);
        $readOnlySummary = $service->getGameSummary($game);
        $statsSummary = $service->getGameStats($game);

        self::assertEquals($finishSummary, $readOnlySummary);
        self::assertEquals($finishSummary, $statsSummary);
    }

    /**
     * @throws ReflectionException
     */
    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty($object, $property);
        $ref->setValue($object, $value);
    }

    private function createService(
        EntityManagerInterface $entityManager,
        GamePlayersRepositoryInterface $gamePlayersRepository,
        RoundThrowsRepositoryInterface $roundThrowsRepository,
        RoundRepositoryInterface $roundRepository
    ): GameFinishService {
        $access = $this->createMock(GameAccessServiceInterface::class);
        $access->method('assertPlayerInGameOrAdmin')->willReturn(new User());

        return new GameFinishService(
            $entityManager,
            $gamePlayersRepository,
            $roundThrowsRepository,
            $roundRepository,
            $access
        );
    }
}
