<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\GameSummaryResponseDto;
use App\Dto\ParticipantTotalsDto;
use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\GameRepositoryInterface;
use App\Repository\PlayerStatisticsRepositoryInterface;
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
    public function testRecordCheckoutSetsPlacementsWinnerAndFinishesWithoutTransaction(): void
    {
        $game = (new Game())->setStartScore(301)->setStatus(GameStatus::Started);
        $this->setPrivateProperty($game, 'gameId', 42);
        $winner = (new GamePlayers())->setPlayer(new User())->setPosition(1)->setScore(0);
        $second = (new GamePlayers())->setPlayer(new User())->setPosition(2)->setScore(30);
        $third = (new GamePlayers())->setPlayer(new User())->setPosition(3)->setScore(40);
        $game->addGamePlayer($winner)->addGamePlayer($second)->addGamePlayer($third);
        $seats = $this->createMock(GamePlayersRepositoryInterface::class);
        $seats->method('countFinishedPlayers')->willReturnOnConsecutiveCalls(0, 1);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('wrapInTransaction');
        $service = $this->createService($em, $seats, $this->createMock(PlayerStatisticsRepositoryInterface::class));

        $service->recordCheckout($game, $winner);
        self::assertSame(1, $winner->getPlacement());
        self::assertSame($winner->getPlayer(), $game->getWinner());
        self::assertTrue($winner->isWinner());
        self::assertSame(GameStatus::Started, $game->getStatus());

        $second->setScore(0);
        $service->recordCheckout($game, $second);
        self::assertSame(2, $second->getPlacement());
        self::assertSame(3, $third->getPlacement());
        self::assertSame(GameStatus::Finished, $game->getStatus());
        self::assertNotNull($game->getFinishedAt());
        self::assertSame($winner->getPlayer(), $game->getWinner());
    }

    /**
     * @throws ReflectionException
     */
    public function testFinishGameSetsWinnerPlacementsAndAggregates(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 42);
        $game->setStatus(GameStatus::Started);

        $user1 = (new User())->setUsername('Player 1');
        $this->setPrivateProperty($user1, 'id', 1);
        $player1 = (new GamePlayers())
            ->setPlayer($user1)
            ->setPosition(2)
            ->setScore(0); // already finished

        $user2 = (new User())->setUsername('Player 2');
        $this->setPrivateProperty($user2, 'id', 2);
        $player2 = (new GamePlayers())
            ->setPlayer($user2)
            ->setPosition(1)
            ->setScore(10);

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findByGameId')
            ->with(42)
            ->willReturn([$player1, $player2]);
        $gamePlayersRepository->method('countFinishedPlayers')
            ->with(42)
            ->willReturn(1);

        $roundThrowsRepository = $this->createMock(PlayerStatisticsRepositoryInterface::class);
        $roundThrowsRepository->method('getParticipantTotalsForGames')
            ->with([42])
            ->willReturn([42 => [
                1 => new ParticipantTotalsDto(turns: 5, dartsThrown: 15, totalScore: 301.0),
                2 => new ParticipantTotalsDto(turns: 5, dartsThrown: 15, totalScore: 291.0),
            ]]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = $this->createService($entityManager, $gamePlayersRepository, $roundThrowsRepository);

        $summary = $service->finishGame($game);

        self::assertSame(GameStatus::Finished, $game->getStatus());
        self::assertNotNull($game->getFinishedAt());
        self::assertSame(1, $player1->getPlacement());
        self::assertSame(2, $player2->getPlacement());
        // The positions are the turn order and stay as the lobby set them.
        self::assertSame(2, $player1->getPosition());
        self::assertSame(1, $player2->getPosition());
        self::assertSame($user1, $game->getWinner());
        self::assertTrue($player1->isWinner());
        self::assertFalse($player2->isWinner());

        self::assertInstanceOf(GameSummaryResponseDto::class, $summary);
        self::assertSame(42, $summary->gameId);
        self::assertNotNull($summary->finishedAt);
        self::assertSame(1, $summary->winner?->id);
        self::assertSame('Player 1', $summary->winner?->username);
        self::assertSame(5, $summary->winnerRoundsPlayed);
        self::assertSame(60.2, $summary->winnerRoundAverage);

        $firstFinished = $summary->finishedPlayers[0];
        self::assertSame(1, $firstFinished->playerId);
        self::assertSame('Player 1', $firstFinished->username);
        self::assertSame(2, $firstFinished->position);
        self::assertSame(1, $firstFinished->placement);
        self::assertSame(5, $firstFinished->roundsPlayed);
        self::assertSame(60.2, $firstFinished->roundAverage);
    }

    /**
     * @throws ReflectionException
     */
    public function testEachParticipantGetsTheirOwnTurnsAndThreeDartAverage(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 99);
        $game->setStatus(GameStatus::Finished);
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

        $roundThrowsRepository = $this->createMock(PlayerStatisticsRepositoryInterface::class);
        $roundThrowsRepository->method('getParticipantTotalsForGames')
            ->with([99])
            ->willReturn([99 => [
                1 => new ParticipantTotalsDto(turns: 5, dartsThrown: 13, totalScore: 260.0),
                2 => new ParticipantTotalsDto(turns: 10, dartsThrown: 30, totalScore: 291.0),
            ]]);

        $entityManager = $this->createMock(EntityManagerInterface::class);

        $service = $this->createService($entityManager, $gamePlayersRepository, $roundThrowsRepository);

        $summary = $service->getGameSummary($game);

        self::assertInstanceOf(GameSummaryResponseDto::class, $summary);
        self::assertSame(5, $summary->winnerRoundsPlayed);
        self::assertSame(60.0, $summary->winnerRoundAverage);
        self::assertCount(3, $summary->finishedPlayers);
        self::assertSame([5, 10, 0], array_map(static fn($player): ?int => $player->roundsPlayed, $summary->finishedPlayers));
        self::assertSame(29.1, $summary->finishedPlayers[1]->roundAverage);
        // A participant who threw nothing averages 0.
        self::assertSame(0.0, $summary->finishedPlayers[2]->roundAverage);
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

        $roundThrowsRepository = $this->createMock(PlayerStatisticsRepositoryInterface::class);
        $roundThrowsRepository->method('getParticipantTotalsForGames')
            ->with([77])
            ->willReturn([77 => [
                1 => new ParticipantTotalsDto(turns: 6, dartsThrown: 17, totalScore: 301.0),
                2 => new ParticipantTotalsDto(turns: 6, dartsThrown: 18, totalScore: 277.0),
            ]]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = $this->createService($entityManager, $gamePlayersRepository, $roundThrowsRepository);
        $finishedAt = new \DateTimeImmutable('2026-03-10T12:00:00+00:00');

        $finishSummary = $service->finishGame($game, $finishedAt);
        $readOnlySummary = $service->getGameSummary($game);

        self::assertEquals($finishSummary, $readOnlySummary);
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
        PlayerStatisticsRepositoryInterface $roundThrowsRepository
    ): GameFinishService {
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $access = $this->createMock(GameAccessServiceInterface::class);
        $access->method('assertPlayerInGameOrAdmin')->willReturn(new User());

        return new GameFinishService(
            $entityManager,
            $gamePlayersRepository,
            $roundThrowsRepository,
            $access,
            $this->createStub(GameRepositoryInterface::class)
        );
    }
}
