<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ThrowRequest;
use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\Round;
use App\Entity\RoundThrows;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Exception\Game\GameThrowNotAllowedException;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\RoundRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Game\GameThrowService;
use App\Service\Security\GameAccessServiceInterface;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionProperty;

#[AllowMockObjectsWithoutExpectations]
final class GameThrowServiceTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    public function testRecordThrowUpdatesScoreAndPersistsThrow(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 10);
        $game->setStartScore(50);
        $game->setRound(1);
        $game->setStatus(GameStatus::Started);

        $round = new Round();
        $round->setRoundNumber(1);
        $round->setGame($game);

        $user1 = new User()->setUsername('Player 1');
        $this->setPrivateProperty($user1, 'id', 1);
        $player1 = new GamePlayers()
            ->setPlayer($user1)
            ->setScore(50)
            ->setPosition(1);
        $game->addGamePlayer($player1);

        $user2 = new User()->setUsername('Player 2');
        $this->setPrivateProperty($user2, 'id', 2);
        $player2 = new GamePlayers()
            ->setPlayer($user2)
            ->setScore(40)
            ->setPosition(2);
        $game->addGamePlayer($player2);

        $dto = new ThrowRequest();
        $dto->playerId = 1;
        $dto->value = 20;
        $dto->isDouble = false;
        $dto->isTriple = false;

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findOneBy')
            ->with(['game' => 10, 'player' => 1])
            ->willReturn($player1);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->method('findOneBy')
            ->with(['game' => $game, 'roundNumber' => 1])
            ->willReturn($round);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('count')
            ->willReturnOnConsecutiveCalls(0, 1);
        $roundThrowsRepository->method('findOneBy')
            ->willReturn(null);

        $persistedThrow = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (object $entity) use (&$persistedThrow): bool {
                $persistedThrow = $entity;

                return true;
            }));
        $entityManager->expects(self::once())->method('flush');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->createAccessService()
        );

        $service->recordThrow($game, $dto);

        self::assertSame(30, $player1->getScore());
        self::assertNotNull($persistedThrow);
        self::assertSame(20, $persistedThrow->getValue());
        self::assertSame(30, $persistedThrow->getScore());
        self::assertFalse($persistedThrow->isBust());
        self::assertSame(1, $persistedThrow->getThrowNumber());
    }

    /**
     * @throws ReflectionException
     */
    public function testRecordThrowFromLobbyAutoStartsGame(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 10);
        $game->setStartScore(50);
        $game->setStatus(GameStatus::Lobby);

        $round = new Round();
        $round->setRoundNumber(1);
        $round->setGame($game);

        $user1 = new User()->setUsername('Player 1');
        $this->setPrivateProperty($user1, 'id', 1);
        $player1 = new GamePlayers()
            ->setPlayer($user1)
            ->setPosition(1);
        $game->addGamePlayer($player1);

        $user2 = new User()->setUsername('Player 2');
        $this->setPrivateProperty($user2, 'id', 2);
        $player2 = new GamePlayers()
            ->setPlayer($user2)
            ->setPosition(2);
        $game->addGamePlayer($player2);

        $dto = new ThrowRequest();
        $dto->playerId = 1;
        $dto->value = 20;

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findOneBy')
            ->with(['game' => 10, 'player' => 1])
            ->willReturn($player1);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->method('findOneBy')
            ->with(['game' => $game, 'roundNumber' => 1])
            ->willReturn($round);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('count')
            ->willReturnOnConsecutiveCalls(0, 1);
        $roundThrowsRepository->method('findOneBy')
            ->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->createAccessService()
        );

        $service->recordThrow($game, $dto);

        self::assertSame(GameStatus::Started, $game->getStatus());
        self::assertSame(1, $game->getRound());
        self::assertSame(30, $player1->getScore());
        self::assertSame(50, $player2->getScore());
    }

    /**
     * @throws ReflectionException
     */
    public function testRecordThrowThrowsWhenGameIsFinished(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 10);
        $game->setStatus(GameStatus::Finished);

        $dto = new ThrowRequest();
        $dto->playerId = 1;
        $dto->value = 20;

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->expects(self::never())->method('findOneBy');

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $entityManager->expects(self::never())->method('persist');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->createAccessService()
        );

        $this->expectException(GameThrowNotAllowedException::class);
        $service->recordThrow($game, $dto);
    }

    /**
     * @throws ReflectionException
     */
    public function testUndoLastThrowFromFinishedGameRestoresLastPlayerAndReopensGame(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 10);
        $game->setStartScore(301);
        $game->setRound(5);
        $game->setStatus(GameStatus::Finished);
        $game->setFinishedAt(new DateTimeImmutable());

        $winnerUser = (new User())->setUsername('Winner');
        $this->setPrivateProperty($winnerUser, 'id', 1);
        $otherUser = (new User())->setUsername('Other');
        $this->setPrivateProperty($otherUser, 'id', 2);

        $winnerPlayer = (new GamePlayers())
            ->setPlayer($winnerUser)
            ->setScore(0)
            ->setPosition(1)
            ->setIsWinner(true);
        $otherPlayer = (new GamePlayers())
            ->setPlayer($otherUser)
            ->setScore(120)
            ->setPosition(2)
            ->setIsWinner(false);
        $game->setWinner($winnerUser);
        $game->addGamePlayer($winnerPlayer);
        $game->addGamePlayer($otherPlayer);

        $round = (new Round())
            ->setGame($game)
            ->setRoundNumber(5);
        $lastThrow = (new RoundThrows())
            ->setGame($game)
            ->setRound($round)
            ->setPlayer($winnerUser)
            ->setThrowNumber(3)
            ->setValue(40)
            ->setScore(0)
            ->setTimestamp(new DateTime());
        $previousThrow = (new RoundThrows())
            ->setGame($game)
            ->setRound($round)
            ->setPlayer($winnerUser)
            ->setThrowNumber(2)
            ->setValue(20)
            ->setScore(40)
            ->setTimestamp(new DateTime());

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?GamePlayers => isset($criteria['player']) && 1 === $criteria['player']
                ? $winnerPlayer
                : null
        );

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);

        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn('5');
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('innerJoin')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findEntityLatestForGame')
            ->with(10)
            ->willReturn($lastThrow);
        $roundThrowsRepository->method('findLatestForGameAndPlayer')
            ->with(10, 1)
            ->willReturn($previousThrow);
        $roundThrowsRepository->method('createQueryBuilder')
            ->with('rt')
            ->willReturn($queryBuilder);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove')->with($lastThrow);
        $entityManager->expects(self::exactly(2))->method('flush');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->createAccessService()
        );

        $service->undoLastThrow($game);

        self::assertSame(GameStatus::Started, $game->getStatus());
        self::assertNull($game->getFinishedAt());
        self::assertSame(40, $winnerPlayer->getScore());
        self::assertSame(0, $winnerPlayer->getPosition());
        self::assertSame(0, $otherPlayer->getPosition());
        self::assertNull($game->getWinner());
        self::assertFalse((bool) $winnerPlayer->isWinner());
        self::assertFalse((bool) $otherPlayer->isWinner());
        self::assertSame(5, $game->getRound());
    }

    /**
     * @throws ReflectionException
     */
    public function testRecordThrowFinishesGameAndNormalizesFinalPositions(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 10);
        $game->setStartScore(20);
        $game->setRound(1);
        $game->setStatus(GameStatus::Started);

        $round = new Round();
        $round->setRoundNumber(1);
        $round->setGame($game);

        $normalLastUser = (new User())->setUsername('Regular Last');
        $this->setPrivateProperty($normalLastUser, 'id', 1);
        $normalLast = (new GamePlayers())
            ->setPlayer($normalLastUser)
            ->setScore(10)
            ->setPosition(1);

        $guestWinnerUser = (new User())->setUsername('Guest Winner')->setIsGuest(true);
        $this->setPrivateProperty($guestWinnerUser, 'id', 2);
        $guestWinner = (new GamePlayers())
            ->setPlayer($guestWinnerUser)
            ->setScore(0)
            ->setPosition(1)
            ->setIsWinner(true);

        $guestSecondUser = (new User())->setUsername('Guest Second')->setIsGuest(true);
        $this->setPrivateProperty($guestSecondUser, 'id', 3);
        $guestSecond = (new GamePlayers())
            ->setPlayer($guestSecondUser)
            ->setScore(0)
            ->setPosition(2)
            ->setIsWinner(false);

        $normalThirdUser = (new User())->setUsername('Regular Third');
        $this->setPrivateProperty($normalThirdUser, 'id', 4);
        $normalThird = (new GamePlayers())
            ->setPlayer($normalThirdUser)
            ->setScore(10)
            ->setPosition(3)
            ->setIsWinner(false);

        $game->setWinner($guestWinnerUser);
        $game->addGamePlayer($normalLast);
        $game->addGamePlayer($guestWinner);
        $game->addGamePlayer($guestSecond);
        $game->addGamePlayer($normalThird);

        $dto = new ThrowRequest();
        $dto->playerId = 4;
        $dto->value = 10;
        $dto->isDouble = false;
        $dto->isTriple = false;

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findOneBy')
            ->with(['game' => 10, 'player' => 4])
            ->willReturn($normalThird);
        $gamePlayersRepository->method('countFinishedPlayers')
            ->with(10)
            ->willReturn(2);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->method('findOneBy')
            ->with(['game' => $game, 'roundNumber' => 1])
            ->willReturn($round);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('count')
            ->willReturnOnConsecutiveCalls(0, 0);
        $roundThrowsRepository->method('findOneBy')
            ->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->createAccessService()
        );

        $service->recordThrow($game, $dto);

        self::assertSame(GameStatus::Finished, $game->getStatus());
        self::assertNotNull($game->getFinishedAt());
        self::assertSame($guestWinnerUser, $game->getWinner());
        self::assertSame(1, $guestWinner->getPosition());
        self::assertSame(2, $guestSecond->getPosition());
        self::assertSame(3, $normalThird->getPosition());
        self::assertSame(4, $normalLast->getPosition());
    }

    /**
     * @throws ReflectionException
     */
    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty($object, $property);
        $ref->setValue($object, $value);
    }

    private function createAccessService(): GameAccessServiceInterface
    {
        $access = $this->createMock(GameAccessServiceInterface::class);
        $access->method('assertPlayerInGameOrAdmin')->willReturn(new User());

        return $access;
    }
}
