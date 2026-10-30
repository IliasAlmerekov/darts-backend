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
use App\Exception\Game\GameNotFoundException;
use App\Exception\Game\GamePlayerNotActiveException;
use App\Exception\Game\GameThrowNotAllowedException;
use App\Exception\Game\InvalidThrowException;
use App\Repository\GameRepositoryInterface;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\PlayerStatisticsRepositoryInterface;
use App\Repository\RoundRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Game\ActivePlayerResolverInterface;
use App\Service\Game\GameThrowService;
use App\Service\Game\FinishedGameTransition;
use App\Service\Game\UndoThrowService;
use App\Service\Game\FinishedGameTransitionInterface;
use App\Service\Game\GameFinishService;
use App\Service\Game\LockedGameTransaction;
use App\Service\Game\LockedGameTransactionInterface;
use App\Service\Persistence\RetryingTransactionInterface;
use App\Service\Security\GameAccessServiceInterface;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionProperty;

#[AllowMockObjectsWithoutExpectations]
final class GameThrowServiceTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    public function testRecordThrowByGameIdLoadsGameWithinTransactionAndPersistsThrow(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 10);
        $game->setStartScore(50);
        $game->setRound(1);
        $game->setStatus(GameStatus::Started);

        $round = new Round();
        $round->setRoundNumber(1);
        $round->setGame($game);

        $user1 = (new User())->setUsername('Player 1');
        $this->setPrivateProperty($user1, 'id', 1);
        $player1 = (new GamePlayers())
            ->setPlayer($user1)
            ->setScore(50)
            ->setPosition(1);
        $game->addGamePlayer($player1);

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
        $roundThrowsRepository->method('findCurrentRoundStateSnapshot')
            ->with(10, 1)
            ->willReturn([]);

        $gameRepository = $this->createMock(GameRepositoryInterface::class);
        $isInsideTransaction = false;
        $gameRepository->expects(self::once())
            ->method('findOneByGameIdWithSeatsForUpdate')
            ->with(10)
            ->willReturnCallback(function (int $lookupGameId) use (&$isInsideTransaction, $game): Game {
                self::assertTrue($isInsideTransaction, 'Expected game lookup to happen inside transaction callback.');
                self::assertSame(10, $lookupGameId);

                return $game;
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(function (\Closure $fn) use (&$isInsideTransaction): mixed {
                $isInsideTransaction = true;
                try {
                    return $fn();
                } finally {
                    $isInsideTransaction = false;
                }
            });
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->lockedGame($entityManager, $gameRepository),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createStub(FinishedGameTransitionInterface::class)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        $service->recordThrowByGameId(10, $dto);

        self::assertSame(30, $player1->getScore());
    }

    public function testRecordThrowByGameIdThrowsWhenGameDoesNotExist(): void
    {
        $dto = new ThrowRequest();
        $dto->playerId = 1;
        $dto->value = 20;

        $gameRepository = $this->createMock(GameRepositoryInterface::class);
        $gameRepository->expects(self::once())
            ->method('findOneByGameIdWithSeatsForUpdate')
            ->with(999)
            ->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (\Closure $fn) => $fn());

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->lockedGame($entityManager, $gameRepository),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createStub(FinishedGameTransitionInterface::class)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        $this->expectException(GameNotFoundException::class);
        $service->recordThrowByGameId(999, $dto);
    }

    /**
     * @throws ReflectionException
     */
    public function testRecordThrowByGameIdKeepsAuthorizationSemantics(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 10);
        $game->setStartScore(50);
        $game->setRound(1);
        $game->setStatus(GameStatus::Started);

        $round = new Round();
        $round->setRoundNumber(1);
        $round->setGame($game);

        $user1 = (new User())->setUsername('Player 1');
        $this->setPrivateProperty($user1, 'id', 1);
        $player1 = (new GamePlayers())
            ->setPlayer($user1)
            ->setScore(50)
            ->setPosition(1);
        $game->addGamePlayer($player1);

        $dto = new ThrowRequest();
        $dto->playerId = 1;
        $dto->value = 20;

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->expects(self::never())->method('findOneBy');

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->expects(self::never())->method('findOneBy');

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);

        // The admin check runs before the transaction, so the game is never locked.
        $gameRepository = $this->createMock(GameRepositoryInterface::class);
        $gameRepository->expects(self::never())->method('findOneByGameIdWithSeatsForUpdate');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('wrapInTransaction');

        $accessService = $this->createMock(GameAccessServiceInterface::class);
        $accessService->expects(self::once())
            ->method('assertAdmin')
            ->willThrowException(new GamePlayerNotActiveException(1, 2));

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->lockedGame($entityManager, $gameRepository, $accessService),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createStub(FinishedGameTransitionInterface::class)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        $this->expectException(GamePlayerNotActiveException::class);
        $service->recordThrowByGameId(10, $dto);
    }

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

        $user1 = (new User())->setUsername('Player 1');
        $this->setPrivateProperty($user1, 'id', 1);
        $player1 = (new GamePlayers())
            ->setPlayer($user1)
            ->setScore(50)
            ->setPosition(1);
        $game->addGamePlayer($player1);

        $user2 = (new User())->setUsername('Player 2');
        $this->setPrivateProperty($user2, 'id', 2);
        $player2 = (new GamePlayers())
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
        $roundThrowsRepository->method('findCurrentRoundStateSnapshot')
            ->with(10, 1)
            ->willReturn([]);

        $persistedThrow = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (\Closure $fn) => $fn());
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
            $this->lockedGame($entityManager, $game),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createStub(FinishedGameTransitionInterface::class)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        $service->recordThrowByGameId((int) $game->getGameId(), $dto);

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
    public function testRecordThrowUsesDisplayNameSnapshotForLatestThrowPayload(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 13);
        $game->setStartScore(301);
        $game->setRound(1);
        $game->setStatus(GameStatus::Started);

        $round = new Round();
        $round->setRoundNumber(1);
        $round->setGame($game);

        $user = $this->createMock(User::class);
        $user->method('getId')
            ->willReturn(7);
        $user->expects(self::never())
            ->method('getDisplayNameRaw');
        $user->expects(self::never())
            ->method('getUsername');

        $player = (new GamePlayers())
            ->setDisplayNameSnapshot('Snapshot Name')
            ->setScore(301)
            ->setPosition(1)
            ->setPlayer($user);
        $game->addGamePlayer($player);

        $dto = new ThrowRequest();
        $dto->playerId = 7;
        $dto->value = 20;
        $dto->isDouble = false;
        $dto->isTriple = false;

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findOneBy')
            ->with(['game' => 13, 'player' => 7])
            ->willReturn($player);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->method('findOneBy')
            ->with(['game' => $game, 'roundNumber' => 1])
            ->willReturn($round);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findCurrentRoundStateSnapshot')
            ->with(13, 1)
            ->willReturn([]);

        $persistedThrow = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (\Closure $fn) => $fn());
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (object $entity) use (&$persistedThrow): bool {
                if ($entity instanceof RoundThrows) {
                    $persistedThrow = $entity;
                }

                return true;
            }));
        $entityManager->expects(self::once())
            ->method('flush')
            ->willReturnCallback(function () use (&$persistedThrow): void {
                self::assertInstanceOf(RoundThrows::class, $persistedThrow);
                $this->setPrivateProperty($persistedThrow, 'throwId', 999);
            });

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->lockedGame($entityManager, $game),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createStub(FinishedGameTransitionInterface::class)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        $result = $service->recordThrowByGameId((int) $game->getGameId(), $dto);

        self::assertNotNull($result->latestThrow);
        self::assertSame('Snapshot Name', $result->latestThrow['playerName']);
    }

    /**
     * @throws ReflectionException
     */
    public function testRecordThrowFallsBackToPlayerDisplayNameWhenSnapshotIsBlank(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 14);
        $game->setStartScore(301);
        $game->setRound(1);
        $game->setStatus(GameStatus::Started);

        $round = new Round();
        $round->setRoundNumber(1);
        $round->setGame($game);

        $didFlush = false;
        $user = $this->createMock(User::class);
        $user->method('getId')
            ->willReturn(8);
        $user->expects(self::once())
            ->method('getDisplayNameRaw')
            ->willReturnCallback(function () use (&$didFlush): string {
                self::assertFalse($didFlush, 'Display name fallback must be resolved before flush.');

                return 'Fallback Name';
            });
        $user->expects(self::never())
            ->method('getUsername');

        $player = (new GamePlayers())
            ->setDisplayNameSnapshot('   ')
            ->setScore(301)
            ->setPosition(1)
            ->setPlayer($user);
        $game->addGamePlayer($player);

        $dto = new ThrowRequest();
        $dto->playerId = 8;
        $dto->value = 20;
        $dto->isDouble = false;
        $dto->isTriple = false;

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findOneBy')
            ->with(['game' => 14, 'player' => 8])
            ->willReturn($player);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->method('findOneBy')
            ->with(['game' => $game, 'roundNumber' => 1])
            ->willReturn($round);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findCurrentRoundStateSnapshot')
            ->with(14, 1)
            ->willReturn([]);

        $persistedThrow = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (\Closure $fn) => $fn());
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (object $entity) use (&$persistedThrow): bool {
                if ($entity instanceof RoundThrows) {
                    $persistedThrow = $entity;
                }

                return true;
            }));
        $entityManager->expects(self::once())
            ->method('flush')
            ->willReturnCallback(function () use (&$persistedThrow, &$didFlush): void {
                $didFlush = true;
                self::assertInstanceOf(RoundThrows::class, $persistedThrow);
                $this->setPrivateProperty($persistedThrow, 'throwId', 1000);
            });

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->lockedGame($entityManager, $game),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createStub(FinishedGameTransitionInterface::class)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        $result = $service->recordThrowByGameId((int) $game->getGameId(), $dto);

        self::assertNotNull($result->latestThrow);
        self::assertSame('Fallback Name', $result->latestThrow['playerName']);
    }

    /**
     * @throws ReflectionException
     */
    public function testRecordThrowFromLobbyThrowsWhenGameNotStarted(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 10);
        $game->setStartScore(50);
        $game->setStatus(GameStatus::Lobby);

        $round = new Round();
        $round->setRoundNumber(1);
        $round->setGame($game);

        $user1 = (new User())->setUsername('Player 1');
        $this->setPrivateProperty($user1, 'id', 1);
        $player1 = (new GamePlayers())
            ->setPlayer($user1)
            ->setPosition(1);
        $game->addGamePlayer($player1);

        $user2 = (new User())->setUsername('Player 2');
        $this->setPrivateProperty($user2, 'id', 2);
        $player2 = (new GamePlayers())
            ->setPlayer($user2)
            ->setPosition(2);
        $game->addGamePlayer($player2);

        $dto = new ThrowRequest();
        $dto->playerId = 1;
        $dto->value = 20;

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->expects(self::never())->method('findOneBy');

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (\Closure $fn) => $fn());
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->lockedGame($entityManager, $game),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createStub(FinishedGameTransitionInterface::class)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        $this->expectException(GameThrowNotAllowedException::class);
        $service->recordThrowByGameId((int) $game->getGameId(), $dto);
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
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (\Closure $fn) => $fn());
        $entityManager->expects(self::never())->method('flush');
        $entityManager->expects(self::never())->method('persist');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->lockedGame($entityManager, $game),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createStub(FinishedGameTransitionInterface::class)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        $this->expectException(GameThrowNotAllowedException::class);
        $service->recordThrowByGameId((int) $game->getGameId(), $dto);
    }

    /**
     * @throws ReflectionException
     */
    public function testRecordThrowThrowsWhenThrowIsBothDoubleAndTriple(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 10);
        $game->setStartScore(301);
        $game->setRound(1);
        $game->setStatus(GameStatus::Started);

        $round = (new Round())
            ->setRoundNumber(1)
            ->setGame($game);

        $user1 = (new User())->setUsername('Player 1');
        $this->setPrivateProperty($user1, 'id', 1);
        $player1 = (new GamePlayers())
            ->setPlayer($user1)
            ->setScore(301)
            ->setPosition(1);
        $game->addGamePlayer($player1);

        $dto = new ThrowRequest();
        $dto->playerId = 1;
        $dto->value = 20;
        $dto->isDouble = true;
        $dto->isTriple = true;

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findOneBy')
            ->with(['game' => 10, 'player' => 1])
            ->willReturn($player1);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->method('findOneBy')
            ->with(['game' => $game, 'roundNumber' => 1])
            ->willReturn($round);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findCurrentRoundStateSnapshot')
            ->with(10, 1)
            ->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (\Closure $fn) => $fn());
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->lockedGame($entityManager, $game),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createStub(FinishedGameTransitionInterface::class)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        $this->expectException(InvalidThrowException::class);
        $service->recordThrowByGameId((int) $game->getGameId(), $dto);
    }

    /**
     * @return iterable<string, array{int, bool, bool}>
     */
    public static function outOfRangeBaseValues(): iterable
    {
        yield 'double 0' => [0, true, false];
        yield 'triple 0' => [0, false, true];
        yield 'double 21' => [21, true, false];
        yield 'double 24' => [24, true, false];
        yield 'double 26' => [26, true, false];
        yield 'triple 21' => [21, false, true];
        yield 'triple bull' => [25, false, true];
        yield 'single 21' => [21, false, false];
        yield 'single 24' => [24, false, false];
        yield 'single 26' => [26, false, false];
        yield 'single 60' => [60, false, false];
        yield 'double and triple' => [20, true, true];
    }

    /**
     * @throws ReflectionException
     */
    #[DataProvider('outOfRangeBaseValues')]
    public function testRecordThrowThrowsWhenBaseValueIsOutOfRange(int $value, bool $isDouble, bool $isTriple): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 10);
        $game->setStartScore(301);
        $game->setRound(1);
        $game->setStatus(GameStatus::Started);

        $round = (new Round())
            ->setRoundNumber(1)
            ->setGame($game);

        $user1 = (new User())->setUsername('Player 1');
        $this->setPrivateProperty($user1, 'id', 1);
        $player1 = (new GamePlayers())
            ->setPlayer($user1)
            ->setScore(301)
            ->setPosition(1);
        $game->addGamePlayer($player1);

        $dto = new ThrowRequest();
        $dto->playerId = 1;
        $dto->value = $value;
        $dto->isDouble = $isDouble;
        $dto->isTriple = $isTriple;

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findOneBy')
            ->with(['game' => 10, 'player' => 1])
            ->willReturn($player1);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->method('findOneBy')
            ->with(['game' => $game, 'roundNumber' => 1])
            ->willReturn($round);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findCurrentRoundStateSnapshot')
            ->with(10, 1)
            ->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (\Closure $fn) => $fn());
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->lockedGame($entityManager, $game),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createStub(FinishedGameTransitionInterface::class)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        $this->expectException(InvalidThrowException::class);
        $service->recordThrowByGameId((int) $game->getGameId(), $dto);
    }

    /**
     * @return iterable<string, array{int, bool, bool, int}>
     */
    public static function boardBoundaryValues(): iterable
    {
        yield 'single 0 (miss)' => [0, false, false, 0];
        yield 'single 20' => [20, false, false, 20];
        yield 'single bull' => [25, false, false, 25];
        yield 'double 1' => [1, true, false, 2];
        yield 'double 20' => [20, true, false, 40];
        yield 'double bull' => [25, true, false, 50];
        yield 'triple 1' => [1, false, true, 3];
        yield 'triple 20' => [20, false, true, 60];
    }

    /**
     * @throws ReflectionException
     */
    #[DataProvider('boardBoundaryValues')]
    public function testRecordThrowAcceptsBoardBoundaryValues(
        int $value,
        bool $isDouble,
        bool $isTriple,
        int $expectedValue,
    ): void {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 10);
        $game->setStartScore(301);
        $game->setRound(1);
        $game->setStatus(GameStatus::Started);

        $round = (new Round())
            ->setRoundNumber(1)
            ->setGame($game);

        $user1 = (new User())->setUsername('Player 1');
        $this->setPrivateProperty($user1, 'id', 1);
        $player1 = (new GamePlayers())
            ->setPlayer($user1)
            ->setScore(301)
            ->setPosition(1);
        $game->addGamePlayer($player1);

        $dto = new ThrowRequest();
        $dto->playerId = 1;
        $dto->value = $value;
        $dto->isDouble = $isDouble;
        $dto->isTriple = $isTriple;

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findOneBy')
            ->with(['game' => 10, 'player' => 1])
            ->willReturn($player1);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->method('findOneBy')
            ->with(['game' => $game, 'roundNumber' => 1])
            ->willReturn($round);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findCurrentRoundStateSnapshot')
            ->with(10, 1)
            ->willReturn([]);

        $persistedThrow = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (\Closure $fn) => $fn());
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
            $this->lockedGame($entityManager, $game),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createStub(FinishedGameTransitionInterface::class)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        $service->recordThrowByGameId((int) $game->getGameId(), $dto);

        self::assertNotNull($persistedThrow);
        self::assertSame($expectedValue, $persistedThrow->getValue());
        self::assertSame(301 - $expectedValue, $player1->getScore());
    }

    /**
     * @throws ReflectionException
     */
    public function testRecordThrowThrowsWhenPlayerIsNotActive(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 10);
        $game->setStartScore(50);
        $game->setRound(1);
        $game->setStatus(GameStatus::Started);

        $round = new Round();
        $round->setRoundNumber(1);
        $round->setGame($game);

        $user1 = (new User())->setUsername('Player 1');
        $this->setPrivateProperty($user1, 'id', 1);
        $player1 = (new GamePlayers())
            ->setPlayer($user1)
            ->setScore(50)
            ->setPosition(1);
        $game->addGamePlayer($player1);

        $user2 = (new User())->setUsername('Player 2');
        $this->setPrivateProperty($user2, 'id', 2);
        $player2 = (new GamePlayers())
            ->setPlayer($user2)
            ->setScore(40)
            ->setPosition(2);
        $game->addGamePlayer($player2);

        $dto = new ThrowRequest();
        $dto->playerId = 2;
        $dto->value = 20;

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findOneBy')
            ->with(['game' => 10, 'player' => 2])
            ->willReturn($player2);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->method('findOneBy')
            ->with(['game' => $game, 'roundNumber' => 1])
            ->willReturn($round);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findCurrentRoundStateSnapshot')
            ->with(10, 1)
            ->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (\Closure $fn) => $fn());
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->lockedGame($entityManager, $game),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createStub(FinishedGameTransitionInterface::class)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        $this->expectException(GamePlayerNotActiveException::class);
        $service->recordThrowByGameId((int) $game->getGameId(), $dto);
    }

    /**
     * @throws ReflectionException
     */
    public function testRecordThrowThrowsWhenPlayerAlreadyBustedInRound(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 10);
        $game->setStartScore(50);
        $game->setRound(1);
        $game->setStatus(GameStatus::Started);

        $round = new Round();
        $round->setRoundNumber(1);
        $round->setGame($game);

        $user1 = (new User())->setUsername('Player 1');
        $this->setPrivateProperty($user1, 'id', 1);
        $player1 = (new GamePlayers())
            ->setPlayer($user1)
            ->setScore(50)
            ->setPosition(1);
        $game->addGamePlayer($player1);

        $user2 = (new User())->setUsername('Player 2');
        $this->setPrivateProperty($user2, 'id', 2);
        $player2 = (new GamePlayers())
            ->setPlayer($user2)
            ->setScore(50)
            ->setPosition(2);
        $game->addGamePlayer($player2);

        $bustThrow = (new RoundThrows())
            ->setGame($game)
            ->setRound($round)
            ->setPlayer($user1)
            ->setThrowNumber(1)
            ->setValue(60)
            ->setIsBust(true)
            ->setScore(50)
            ->setTimestamp(new DateTime());

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
        $roundThrowsRepository->method('findCurrentRoundStateSnapshot')
            ->with(10, 1)
            ->willReturn([
                1 => [
                    'throwsCount' => 1,
                    'lastThrowNumber' => 1,
                    'lastThrowValue' => 60,
                    'lastThrowBust' => true,
                ],
            ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (\Closure $fn) => $fn());
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->lockedGame($entityManager, $game),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createStub(FinishedGameTransitionInterface::class)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        $this->expectException(GamePlayerNotActiveException::class);
        $service->recordThrowByGameId((int) $game->getGameId(), $dto);
    }

    /**
     * @throws ReflectionException
     */
    public function testRecordThrowPassesLoadedRoundStateSnapshotToSharedActivePlayerResolver(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 11);
        $game->setStartScore(301);
        $game->setRound(2);
        $game->setStatus(GameStatus::Started);

        $round = (new Round())
            ->setRoundNumber(2)
            ->setGame($game);

        $user1 = (new User())->setUsername('Player 1');
        $this->setPrivateProperty($user1, 'id', 1);
        $player1 = (new GamePlayers())
            ->setPlayer($user1)
            ->setScore(301)
            ->setPosition(1);
        $game->addGamePlayer($player1);

        $user2 = (new User())->setUsername('Player 2');
        $this->setPrivateProperty($user2, 'id', 2);
        $player2 = (new GamePlayers())
            ->setPlayer($user2)
            ->setScore(301)
            ->setPosition(2);
        $game->addGamePlayer($player2);

        $dto = new ThrowRequest();
        $dto->playerId = 1;
        $dto->value = 20;

        $roundStateSnapshot = [
            1 => [
                'throwsCount' => 1,
                'lastThrowNumber' => 1,
                'lastThrowValue' => 20,
                'lastThrowBust' => false,
            ],
        ];

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findOneBy')
            ->with(['game' => 11, 'player' => 1])
            ->willReturn($player1);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->method('findOneBy')
            ->with(['game' => $game, 'roundNumber' => 2])
            ->willReturn($round);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->expects(self::once())
            ->method('findCurrentRoundStateSnapshot')
            ->with(11, 2)
            ->willReturn($roundStateSnapshot);

        $activePlayerResolver = $this->createMock(ActivePlayerResolverInterface::class);
        $activePlayerResolver->expects(self::once())
            ->method('resolveActivePlayer')
            ->with($game, $roundStateSnapshot)
            ->willReturn(1);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (\Closure $fn) => $fn());
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->lockedGame($entityManager, $game),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createStub(FinishedGameTransitionInterface::class)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
            $activePlayerResolver,
        );

        $service->recordThrowByGameId((int) $game->getGameId(), $dto);

        self::assertSame(281, $player1->getScore());
    }

    /**
     * @throws ReflectionException
     */
    public function testRecordThrowReturnsEmptyCurrentRoundSnapshotAfterRoundAdvance(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 12);
        $game->setStartScore(301);
        $game->setRound(1);
        $game->setStatus(GameStatus::Started);

        $round = (new Round())
            ->setRoundNumber(1)
            ->setGame($game);

        $user = (new User())->setUsername('Player 1');
        $this->setPrivateProperty($user, 'id', 1);
        $player = (new GamePlayers())
            ->setPlayer($user)
            ->setScore(301)
            ->setPosition(1);
        $game->addGamePlayer($player);

        $dto = new ThrowRequest();
        $dto->playerId = 1;
        $dto->value = 20;
        $dto->isDouble = false;
        $dto->isTriple = false;

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findOneBy')
            ->with(['game' => 12, 'player' => 1])
            ->willReturn($player);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->method('findOneBy')
            ->with(['game' => $game, 'roundNumber' => 1])
            ->willReturn($round);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findCurrentRoundStateSnapshot')
            ->with(12, 1)
            ->willReturn([
                1 => [
                    'throwsCount' => 2,
                    'lastThrowNumber' => 2,
                    'lastThrowValue' => 60,
                    'lastThrowBust' => false,
                ],
            ]);

        $persistedEntities = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (\Closure $fn) => $fn());
        $entityManager->expects(self::exactly(2))
            ->method('persist')
            ->with(self::callback(function (object $entity) use (&$persistedEntities): bool {
                $persistedEntities[] = $entity;

                return true;
            }));
        $entityManager->expects(self::once())->method('flush');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->lockedGame($entityManager, $game),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createStub(FinishedGameTransitionInterface::class)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        $result = $service->recordThrowByGameId((int) $game->getGameId(), $dto);

        self::assertSame(2, $game->getRound());
        self::assertSame([], $result->currentRoundStateSnapshot);
        self::assertCount(2, $persistedEntities);
        self::assertInstanceOf(RoundThrows::class, $persistedEntities[0]);
        self::assertInstanceOf(Round::class, $persistedEntities[1]);
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
            ->setPlacement(1)
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
            ->setThrowId(103)
            ->setValue(40)
            ->setScore(0)
            ->setTimestamp(new DateTime());
        $previousThrow = (new RoundThrows())
            ->setGame($game)
            ->setRound($round)
            ->setPlayer($winnerUser)
            ->setThrowNumber(2)
            ->setThrowId(102)
            ->setValue(20)
            ->setScore(40)
            ->setTimestamp(new DateTime());

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findEntityLatestForGame')
            ->with(10)
            ->willReturn($lastThrow);
        $roundThrowsRepository->method('findLatestForGameAndPlayerBeforeThrow')
            ->with(10, 1, 103)
            ->willReturn($previousThrow);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('wrapInTransaction')
            ->with(self::isInstanceOf(\Closure::class))
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $entityManager->expects(self::once())->method('remove')->with($lastThrow);
        $entityManager->expects(self::once())->method('flush');

        $gameRepository = $this->createMock(GameRepositoryInterface::class);

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->lockedGame($entityManager, $game),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createReopenService($gameRepository, 10)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        $undoneThrow = $service->undoLastThrow($game);

        self::assertSame(GameStatus::Started, $game->getStatus());
        self::assertNull($game->getFinishedAt());
        self::assertSame(40, $winnerPlayer->getScore());
        self::assertSame(1, $winnerPlayer->getPosition());
        self::assertSame(2, $otherPlayer->getPosition());
        self::assertNull($winnerPlayer->getPlacement());
        self::assertNull($otherPlayer->getPlacement());
        self::assertNull($game->getWinner());
        self::assertFalse((bool) $winnerPlayer->isWinner());
        self::assertFalse((bool) $otherPlayer->isWinner());
        self::assertSame(5, $game->getRound());
        self::assertNotNull($undoneThrow);
        self::assertSame(40, $undoneThrow->value);
        self::assertSame(1, $undoneThrow->playerId);
        self::assertSame(5, $undoneThrow->roundNumber);
    }

    /**
     * @throws ReflectionException
     */
    public function testUndoServiceRestoresScoreBeforeTakingBackPlacement(): void
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
            ->setPlacement(1)
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
            ->setThrowId(103)
            ->setValue(40)
            ->setScore(0)
            ->setTimestamp(new DateTime());
        $previousThrow = (new RoundThrows())
            ->setGame($game)
            ->setRound($round)
            ->setPlayer($winnerUser)
            ->setThrowNumber(2)
            ->setThrowId(102)
            ->setValue(20)
            ->setScore(40)
            ->setTimestamp(new DateTime());

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findEntityLatestForGame')
            ->with(10)
            ->willReturn($lastThrow);
        $roundThrowsRepository->method('findLatestForGameAndPlayerBeforeThrow')
            ->with(10, 1, 103)
            ->willReturn($previousThrow);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove')->with($lastThrow);
        $entityManager->expects(self::once())->method('flush');

        $gameRepository = $this->createMock(GameRepositoryInterface::class);

        $service = new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createReopenService($gameRepository, 10));

        $undoneThrow = $service->undo($game);

        self::assertSame(GameStatus::Started, $game->getStatus());
        self::assertNull($game->getFinishedAt());
        self::assertSame(40, $winnerPlayer->getScore());
        self::assertSame(1, $winnerPlayer->getPosition());
        self::assertSame(2, $otherPlayer->getPosition());
        self::assertNull($winnerPlayer->getPlacement());
        self::assertNull($otherPlayer->getPlacement());
        self::assertNull($game->getWinner());
        self::assertFalse((bool) $winnerPlayer->isWinner());
        self::assertFalse((bool) $otherPlayer->isWinner());
        self::assertSame(5, $game->getRound());
        self::assertNotNull($undoneThrow);
        self::assertSame(40, $undoneThrow->value);
        self::assertSame(1, $undoneThrow->playerId);
        self::assertSame(5, $undoneThrow->roundNumber);
    }

    /**
     * @throws ReflectionException
     */
    public function testUndoCheckoutKeepsEarlierWinnerPlacement(): void
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
            ->setPlacement(1)
            ->setIsWinner(true);
        $otherPlayer = (new GamePlayers())
            ->setPlayer($otherUser)
            ->setScore(120)
            ->setPosition(2)
            ->setIsWinner(false);
        $thirdUser = (new User())->setUsername('Earlier winner');
        $this->setPrivateProperty($thirdUser, 'id', 3);
        $thirdPlayer = (new GamePlayers())->setPlayer($thirdUser)->setScore(0)->setPlacement(1)->setIsWinner(true);
        $winnerPlayer->setPlacement(2)->setIsWinner(false);
        $otherPlayer->setPlacement(3);
        $game->addGamePlayer($thirdPlayer);
        $game->setWinner($thirdUser);
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
            ->setThrowId(103)
            ->setValue(40)
            ->setScore(0)
            ->setTimestamp(new DateTime());
        $previousThrow = (new RoundThrows())
            ->setGame($game)
            ->setRound($round)
            ->setPlayer($winnerUser)
            ->setThrowNumber(2)
            ->setThrowId(102)
            ->setValue(20)
            ->setScore(40)
            ->setTimestamp(new DateTime());

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findEntityLatestForGame')
            ->with(10)
            ->willReturn($lastThrow);
        $roundThrowsRepository->method('findLatestForGameAndPlayerBeforeThrow')
            ->with(10, 1, 103)
            ->willReturn($previousThrow);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('wrapInTransaction')
            ->with(self::isInstanceOf(\Closure::class))
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $entityManager->expects(self::once())->method('remove')->with($lastThrow);
        $entityManager->expects(self::once())->method('flush');

        $gameRepository = $this->createMock(GameRepositoryInterface::class);

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->lockedGame($entityManager, $game),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createReopenService($gameRepository, 10)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        $undoneThrow = $service->undoLastThrow($game);

        self::assertSame(GameStatus::Started, $game->getStatus());
        self::assertNull($game->getFinishedAt());
        self::assertSame(40, $winnerPlayer->getScore());
        self::assertSame(1, $winnerPlayer->getPosition());
        self::assertSame(2, $otherPlayer->getPosition());
        self::assertNull($winnerPlayer->getPlacement());
        self::assertNull($otherPlayer->getPlacement());
        self::assertSame($thirdUser, $game->getWinner());
        self::assertSame(1, $thirdPlayer->getPlacement());
        self::assertTrue((bool) $thirdPlayer->isWinner());
        self::assertFalse((bool) $winnerPlayer->isWinner());
        self::assertFalse((bool) $otherPlayer->isWinner());
        self::assertSame(5, $game->getRound());
        self::assertNotNull($undoneThrow);
        self::assertSame(40, $undoneThrow->value);
        self::assertSame(1, $undoneThrow->playerId);
        self::assertSame(5, $undoneThrow->roundNumber);
    }

    /**
     * @throws ReflectionException
     */
    public function testUndoRejectsOlderFinishedGameWithoutChangingState(): void
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
            ->setPlacement(1)
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
            ->setThrowId(103)
            ->setValue(40)
            ->setScore(0)
            ->setTimestamp(new DateTime());
        $previousThrow = (new RoundThrows())
            ->setGame($game)
            ->setRound($round)
            ->setPlayer($winnerUser)
            ->setThrowNumber(2)
            ->setThrowId(102)
            ->setValue(20)
            ->setScore(40)
            ->setTimestamp(new DateTime());

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findEntityLatestForGame')
            ->with(10)
            ->willReturn($lastThrow);
        $roundThrowsRepository->method('findLatestForGameAndPlayerBeforeThrow')
            ->with(10, 1, 103)
            ->willReturn($previousThrow);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('wrapInTransaction')
            ->with(self::isInstanceOf(\Closure::class))
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $entityManager->expects(self::never())->method('remove');
        $entityManager->expects(self::never())->method('flush');

        $gameRepository = $this->createMock(GameRepositoryInterface::class);

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->lockedGame($entityManager, $game),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createReopenService($gameRepository, 11)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        try {
            $service->undoLastThrow($game);
            self::fail('Expected the newest game guard to reject undo.');
        } catch (\App\Exception\Game\GameNotNewestException) {
            self::assertSame(GameStatus::Finished, $game->getStatus());
            self::assertNotNull($game->getFinishedAt());
            self::assertSame(0, $winnerPlayer->getScore());
            self::assertSame(1, $winnerPlayer->getPlacement());
            self::assertSame($winnerUser, $game->getWinner());
            self::assertSame(5, $game->getRound());
        }
    }

    /**
     * @throws ReflectionException
     */
    public function testUndoFinishedGameWithoutThrowsChangesNothing(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 10);
        $game->setStatus(GameStatus::Finished);
        $finishedAt = new DateTimeImmutable();
        $game->setFinishedAt($finishedAt);
        $seats = $this->createMock(GamePlayersRepositoryInterface::class);
        $rounds = $this->createMock(RoundRepositoryInterface::class);
        $throws = $this->createMock(RoundThrowsRepositoryInterface::class);
        $throws->method('findEntityLatestForGame')->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $em->expects(self::never())->method('remove');
        $em->expects(self::never())->method('flush');
        $repository = $this->createMock(GameRepositoryInterface::class);
        $repository->expects(self::never())->method('abortStartedGames');
        $service = new GameThrowService($seats, $rounds, $throws, $em, $this->lockedGame($em, $game), new UndoThrowService($rounds, $throws, $em, $this->createReopenService($repository, 11)), new GameFinishService($seats, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)));

        self::assertNull($service->undoLastThrow($game));
        self::assertSame(GameStatus::Finished, $game->getStatus());
        self::assertSame($finishedAt, $game->getFinishedAt());
    }

    /**
     * @throws ReflectionException
     */
    public function testUndoLastBustRestoresPlayerScoreWithoutExtraRoundQuery(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 20);
        $game->setStartScore(301);
        $game->setRound(4);
        $game->setStatus(GameStatus::Started);

        $playerUser = (new User())->setUsername('Busted Player');
        $this->setPrivateProperty($playerUser, 'id', 1);
        $otherUser = (new User())->setUsername('Other');
        $this->setPrivateProperty($otherUser, 'id', 2);

        $player = (new GamePlayers())
            ->setPlayer($playerUser)
            ->setScore(181)
            ->setPosition(1)
            ->setIsWinner(false);
        $otherPlayer = (new GamePlayers())
            ->setPlayer($otherUser)
            ->setScore(160)
            ->setPosition(2)
            ->setIsWinner(false);
        $game->addGamePlayer($player);
        $game->addGamePlayer($otherPlayer);

        $round = (new Round())
            ->setGame($game)
            ->setRoundNumber(4);
        $lastBustThrow = (new RoundThrows())
            ->setGame($game)
            ->setRound($round)
            ->setPlayer($playerUser)
            ->setThrowId(401)
            ->setThrowNumber(3)
            ->setValue(180)
            ->setIsBust(true)
            ->setScore(181)
            ->setTimestamp(new DateTime());
        $previousPlayerThrow = (new RoundThrows())
            ->setGame($game)
            ->setRound($round)
            ->setPlayer($playerUser)
            ->setThrowId(400)
            ->setThrowNumber(2)
            ->setValue(60)
            ->setScore(181)
            ->setTimestamp(new DateTime());
        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findEntityLatestForGame')
            ->with(20)
            ->willReturn($lastBustThrow);
        $roundThrowsRepository->method('findLatestForGameAndPlayerBeforeThrow')
            ->with(20, 1, 401)
            ->willReturn($previousPlayerThrow);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $entityManager->expects(self::once())->method('remove')->with($lastBustThrow);
        $entityManager->expects(self::once())->method('flush');

        $gameRepository = $this->createMock(GameRepositoryInterface::class);

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->lockedGame($entityManager, $game),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createStub(FinishedGameTransitionInterface::class)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        $undoneThrow = $service->undoLastThrow($game);

        self::assertNotNull($undoneThrow);
        self::assertTrue($undoneThrow->isBust);
        self::assertSame(181, $player->getScore());
        self::assertSame(1, $player->getPosition());
        self::assertSame(2, $otherPlayer->getPosition());
        self::assertNull($player->getPlacement());
        self::assertNull($otherPlayer->getPlacement());
        self::assertSame(4, $game->getRound());
        self::assertNull($game->getWinner());
        self::assertFalse((bool) $player->isWinner());
        self::assertFalse((bool) $otherPlayer->isWinner());
    }

    /**
     * @throws ReflectionException
     */
    public function testUndoWinningThrowKeepsStartedGameAndRestoresWinnerState(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 30);
        $game->setStartScore(301);
        $game->setRound(3);
        $game->setStatus(GameStatus::Started);

        $winnerUser = (new User())->setUsername('Winner');
        $this->setPrivateProperty($winnerUser, 'id', 1);
        $secondUser = (new User())->setUsername('Second');
        $this->setPrivateProperty($secondUser, 'id', 2);
        $thirdUser = (new User())->setUsername('Third');
        $this->setPrivateProperty($thirdUser, 'id', 3);

        $winnerPlayer = (new GamePlayers())
            ->setPlayer($winnerUser)
            ->setScore(0)
            ->setPosition(1)
            ->setPlacement(1)
            ->setIsWinner(true);
        $secondPlayer = (new GamePlayers())
            ->setPlayer($secondUser)
            ->setScore(120)
            ->setPosition(2)
            ->setIsWinner(false);
        $thirdPlayer = (new GamePlayers())
            ->setPlayer($thirdUser)
            ->setScore(80)
            ->setPosition(3)
            ->setIsWinner(false);
        $game->setWinner($winnerUser);
        $game->addGamePlayer($winnerPlayer);
        $game->addGamePlayer($secondPlayer);
        $game->addGamePlayer($thirdPlayer);

        $round = (new Round())
            ->setGame($game)
            ->setRoundNumber(3);
        $winningThrow = (new RoundThrows())
            ->setGame($game)
            ->setRound($round)
            ->setPlayer($winnerUser)
            ->setThrowId(303)
            ->setThrowNumber(3)
            ->setValue(40)
            ->setIsDouble(true)
            ->setScore(0)
            ->setTimestamp(new DateTime());
        $previousWinnerThrow = (new RoundThrows())
            ->setGame($game)
            ->setRound($round)
            ->setPlayer($winnerUser)
            ->setThrowId(302)
            ->setThrowNumber(2)
            ->setValue(20)
            ->setScore(40)
            ->setTimestamp(new DateTime());
        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findEntityLatestForGame')
            ->with(30)
            ->willReturn($winningThrow);
        $roundThrowsRepository->method('findLatestForGameAndPlayerBeforeThrow')
            ->with(30, 1, 303)
            ->willReturn($previousWinnerThrow);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $entityManager->expects(self::once())->method('remove')->with($winningThrow);
        $entityManager->expects(self::once())->method('flush');

        $gameRepository = $this->createMock(GameRepositoryInterface::class);

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->lockedGame($entityManager, $game),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createStub(FinishedGameTransitionInterface::class)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        $undoneThrow = $service->undoLastThrow($game);

        self::assertNotNull($undoneThrow);
        self::assertSame(40, $winnerPlayer->getScore());
        self::assertSame(1, $winnerPlayer->getPosition());
        self::assertSame(2, $secondPlayer->getPosition());
        self::assertSame(3, $thirdPlayer->getPosition());
        self::assertNull($winnerPlayer->getPlacement());
        self::assertSame(GameStatus::Started, $game->getStatus());
        self::assertNull($game->getFinishedAt());
        self::assertNull($game->getWinner());
        self::assertFalse((bool) $winnerPlayer->isWinner());
        self::assertFalse((bool) $secondPlayer->isWinner());
        self::assertFalse((bool) $thirdPlayer->isWinner());
        self::assertSame(3, $game->getRound());
    }

    /**
     * @throws ReflectionException
     */
    public function testUndoFirstThrowOfNewRoundKeepsCurrentRoundNumber(): void
    {
        // Scenario: 2 players, round 1 complete, player 1 throws first in round 2.
        // Undoing that throw must leave game.round = 2, not regress to round 1.
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 40);
        $game->setStartScore(301);
        $game->setRound(2);
        $game->setStatus(GameStatus::Started);

        $playerUser = (new User())->setUsername('Player1');
        $this->setPrivateProperty($playerUser, 'id', 1);
        $otherUser = (new User())->setUsername('Player2');
        $this->setPrivateProperty($otherUser, 'id', 2);

        $playerGamePlayer = (new GamePlayers())
            ->setPlayer($playerUser)
            ->setScore(241)
            ->setIsWinner(false);
        $otherGamePlayer = (new GamePlayers())
            ->setPlayer($otherUser)
            ->setScore(241)
            ->setIsWinner(false);
        $game->addGamePlayer($playerGamePlayer);
        $game->addGamePlayer($otherGamePlayer);

        $round2 = (new Round())->setGame($game)->setRoundNumber(2);
        $round1 = (new Round())->setGame($game)->setRoundNumber(1);

        // Last throw: player 1's first throw in round 2
        $lastThrow = (new RoundThrows())
            ->setGame($game)
            ->setRound($round2)
            ->setPlayer($playerUser)
            ->setThrowId(201)
            ->setThrowNumber(1)
            ->setValue(60)
            ->setScore(241)
            ->setTimestamp(new DateTime());

        // Previous player throw: player 1 has no previous throw in round 2
        $previousPlayerThrow = null;

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findEntityLatestForGame')
            ->with(40)
            ->willReturn($lastThrow);
        $roundThrowsRepository->method('findLatestForGameAndPlayerBeforeThrow')
            ->with(40, 1, 201)
            ->willReturn($previousPlayerThrow);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $entityManager->expects(self::once())->method('remove')->with($lastThrow);
        $entityManager->expects(self::once())->method('flush');

        $gameRepository = $this->createMock(GameRepositoryInterface::class);

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->lockedGame($entityManager, $game),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createStub(FinishedGameTransitionInterface::class)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        $service->undoLastThrow($game);

        // Round must stay at 2 — player 1 needs to re-throw in round 2,
        // not regress to round 1 where all throws are already completed.
        self::assertSame(2, $game->getRound());
        self::assertSame(301, $playerGamePlayer->getScore());
    }

    /**
     * @throws ReflectionException
     */
    public function testRecordThrowFinishesGameAndNormalizesFinalPlacementsWithoutTouchingPositions(): void
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
            ->setPosition(4);

        $guestWinnerUser = (new User())->setUsername('Guest Winner')->setIsGuest(true);
        $this->setPrivateProperty($guestWinnerUser, 'id', 2);
        $guestWinner = (new GamePlayers())
            ->setPlayer($guestWinnerUser)
            ->setScore(0)
            ->setPosition(1)
            ->setPlacement(1)
            ->setIsWinner(true);

        $guestSecondUser = (new User())->setUsername('Guest Second')->setIsGuest(true);
        $this->setPrivateProperty($guestSecondUser, 'id', 3);
        $guestSecond = (new GamePlayers())
            ->setPlayer($guestSecondUser)
            ->setScore(0)
            ->setPosition(2)
            ->setPlacement(2)
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

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->method('findOneBy')
            ->with(['game' => $game, 'roundNumber' => 1])
            ->willReturn($round);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findCurrentRoundStateSnapshot')
            ->with(10, 1)
            ->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (\Closure $fn) => $fn());
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->lockedGame($entityManager, $game),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createStub(FinishedGameTransitionInterface::class)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        $service->recordThrowByGameId((int) $game->getGameId(), $dto);

        self::assertSame(GameStatus::Finished, $game->getStatus());
        self::assertNotNull($game->getFinishedAt());
        self::assertSame($guestWinnerUser, $game->getWinner());
        self::assertSame(1, $guestWinner->getPlacement());
        self::assertSame(2, $guestSecond->getPlacement());
        self::assertSame(3, $normalThird->getPlacement());
        self::assertSame(4, $normalLast->getPlacement());
        // The positions are the turn order and stay as the lobby set them.
        self::assertSame([4, 1, 2, 3], [$normalLast->getPosition(), $guestWinner->getPosition(), $guestSecond->getPosition(), $normalThird->getPosition()]);
    }

    public function testSecondCheckoutGetsSecondPlacementAndFinishesGame(): void
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
            ->setPosition(4);

        $guestWinnerUser = (new User())->setUsername('Guest Winner')->setIsGuest(true);
        $this->setPrivateProperty($guestWinnerUser, 'id', 2);
        $guestWinner = (new GamePlayers())
            ->setPlayer($guestWinnerUser)
            ->setScore(0)
            ->setPosition(1)
            ->setPlacement(1)
            ->setIsWinner(true);

        $guestSecondUser = (new User())->setUsername('Guest Second')->setIsGuest(true);
        $this->setPrivateProperty($guestSecondUser, 'id', 3);
        $guestSecond = (new GamePlayers())
            ->setPlayer($guestSecondUser)
            ->setScore(10)
            ->setPosition(2)
            ->setIsWinner(false);

        $game->setWinner($guestWinnerUser);
        $game->addGamePlayer($normalLast);
        $game->addGamePlayer($guestWinner);
        $game->addGamePlayer($guestSecond);

        $dto = new ThrowRequest();
        $dto->playerId = 3;
        $dto->value = 10;
        $dto->isDouble = false;
        $dto->isTriple = false;

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findOneBy')
            ->with(['game' => 10, 'player' => 3])
            ->willReturn($guestSecond);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->method('findOneBy')
            ->with(['game' => $game, 'roundNumber' => 1])
            ->willReturn($round);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findCurrentRoundStateSnapshot')
            ->with(10, 1)
            ->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (\Closure $fn) => $fn());
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->lockedGame($entityManager, $game),
            new UndoThrowService($roundRepository, $roundThrowsRepository, $entityManager, $this->createStub(FinishedGameTransitionInterface::class)),
            new GameFinishService($gamePlayersRepository, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)),
        );

        $service->recordThrowByGameId((int) $game->getGameId(), $dto);

        self::assertSame(GameStatus::Finished, $game->getStatus());
        self::assertNotNull($game->getFinishedAt());
        self::assertSame($guestWinnerUser, $game->getWinner());
        self::assertSame(1, $guestWinner->getPlacement());
        self::assertSame(2, $guestSecond->getPlacement());
        self::assertSame(3, $normalLast->getPlacement());
        self::assertTrue($guestWinner->isWinner());
        self::assertFalse($guestSecond->isWinner());
    }

    public function testScoringAndBustRulesThroughRecordThrow(): void
    {
        // Each row is double out, triple out, score, value, double, triple, expected score, bust.
        $cases = [
            [false, false, 20, 20, false, false, 0, false],
            [false, false, 21, 20, false, false, 1, false],
            [false, false, 22, 20, false, false, 2, false],
            [false, false, 19, 20, false, false, 19, true],
            [true, false, 20, 10, true, false, 0, false],
            [true, false, 20, 20, false, false, 20, true],
            [true, false, 21, 20, false, false, 21, true],
            [true, false, 22, 20, false, false, 2, false],
            [true, false, 19, 20, false, false, 19, true],
            [false, true, 18, 6, false, true, 0, false],
            [false, true, 20, 20, false, false, 20, true],
            [false, true, 21, 20, false, false, 21, true],
            [false, true, 22, 20, false, false, 22, true],
            [false, true, 19, 20, false, false, 19, true],
            [true, false, 21, 20, false, false, 41, true, 20],
        ];
        foreach ($cases as $case) {
            [$doubleOut, $tripleOut, $score, $value, $double, $triple, $expected, $bust] = $case;
            $earlierPoints = $case[8] ?? 0;
            $game = (new Game())->setStartScore(301)->setRound(1)->setStatus(GameStatus::Started)
                ->setOutRule($doubleOut ? \App\Enum\OutRule::Double : ($tripleOut ? \App\Enum\OutRule::Triple : \App\Enum\OutRule::Single));
            $this->setPrivateProperty($game, 'gameId', 10);
            $user = (new User())->setUsername('Player');
            $this->setPrivateProperty($user, 'id', 1);
            $seat = (new GamePlayers())->setPlayer($user)->setScore($score)->setPosition(1);
            $game->addGamePlayer($seat);
            foreach ([2, 3] as $id) {
                $other = (new User())->setUsername('Player ' . $id);
                $this->setPrivateProperty($other, 'id', $id);
                $game->addGamePlayer((new GamePlayers())->setPlayer($other)->setScore(100)->setPosition($id));
            }
            $round = (new Round())->setGame($game)->setRoundNumber(1);
            $dto = new ThrowRequest();
            $dto->playerId = 1;
            $dto->value = $value;
            $dto->isDouble = $double;
            $dto->isTriple = $triple;
            $seats = $this->createMock(GamePlayersRepositoryInterface::class);
            $seats->method('findOneBy')->willReturn($seat);
            $rounds = $this->createMock(RoundRepositoryInterface::class);
            $rounds->method('findOneBy')->willReturn($round);
            $throws = $this->createMock(RoundThrowsRepositoryInterface::class);
            $throws->method('findCurrentRoundStateSnapshot')->willReturn($earlierPoints ? [1 => ['throwsCount' => 1, 'lastThrowNumber' => 1, 'lastThrowValue' => $earlierPoints, 'lastThrowBust' => false]] : []);
            $throws->method('findBy')->willReturn($earlierPoints ? [(new RoundThrows())->setValue($earlierPoints)->setIsBust(false)] : []);
            $persisted = null;
            $em = $this->createMock(EntityManagerInterface::class);
            $em->method('wrapInTransaction')->willReturnCallback(static fn (\Closure $fn) => $fn());
            $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void { $persisted = $entity; });
            $service = new GameThrowService($seats, $rounds, $throws, $em, $this->lockedGame($em, $game), new UndoThrowService($rounds, $throws, $em, $this->createStub(FinishedGameTransitionInterface::class)), new GameFinishService($seats, $this->createStub(PlayerStatisticsRepositoryInterface::class), $this->createAccessService(), $this->createStub(LockedGameTransactionInterface::class)));
            $service->recordThrowByGameId((int) $game->getGameId(), $dto);
            self::assertSame($expected, $seat->getScore());
            self::assertInstanceOf(RoundThrows::class, $persisted);
            self::assertSame($bust, $persisted->isBust());
            if (!$bust && 0 === $expected) {
                self::assertSame(1, $seat->getPlacement());
                self::assertSame($user, $game->getWinner());
                self::assertSame(GameStatus::Started, $game->getStatus());
            }
        }
    }

    /**
     * @throws ReflectionException
     */
    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty($object, $property);
        $ref->setValue($object, $value);
    }

    private function createReopenService(GameRepositoryInterface&Stub $gameRepository, int $newestGameId): FinishedGameTransition
    {
        $gameRepository->method('findHighestGameId')->willReturn($newestGameId);

        return new FinishedGameTransition($gameRepository);
    }

    private function lockedGame(EntityManagerInterface $entityManager, Game|GameRepositoryInterface $game, ?GameAccessServiceInterface $access = null): LockedGameTransaction
    {
        $games = $game;
        if ($game instanceof Game) {
            $games = $this->createStub(GameRepositoryInterface::class);
            $games->method('findOneByGameIdWithSeatsForUpdate')->willReturn($game);
        }

        return new LockedGameTransaction($access ?? $this->createAccessService(), $entityManager, $this->createStub(RetryingTransactionInterface::class), $games);
    }

    private function createAccessService(): GameAccessServiceInterface
    {
        $access = $this->createMock(GameAccessServiceInterface::class);
        $access->method('assertAdmin')->willReturn(new User());

        return $access;
    }

}
