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
use App\Repository\RoundRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Game\ActivePlayerResolverInterface;
use App\Service\Game\GameThrowService;
use App\Service\Security\GameAccessServiceInterface;
use DateTime;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
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
            ->method('findOneByGameIdForUpdate')
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
            $this->createAccessService(),
            $gameRepository,
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
            ->method('findOneByGameIdForUpdate')
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
            $this->createAccessService(),
            $gameRepository,
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

        $gameRepository = $this->createMock(GameRepositoryInterface::class);
        $gameRepository->expects(self::once())
            ->method('findOneByGameIdForUpdate')
            ->with(10)
            ->willReturn($game);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (\Closure $fn) => $fn());

        $accessService = $this->createMock(GameAccessServiceInterface::class);
        $accessService->expects(self::once())
            ->method('assertPlayerInGameOrAdmin')
            ->with($game)
            ->willThrowException(new GamePlayerNotActiveException(1, 2));

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $accessService,
            $gameRepository,
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
            $this->createAccessService(),
            $this->createMock(GameRepositoryInterface::class),
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
            $this->createAccessService(),
            $this->createMock(GameRepositoryInterface::class),
        );

        $result = $service->recordThrow($game, $dto);

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
            $this->createAccessService(),
            $this->createMock(GameRepositoryInterface::class),
        );

        $result = $service->recordThrow($game, $dto);

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
            $this->createAccessService(),
            $this->createMock(GameRepositoryInterface::class),
        );

        $this->expectException(GameThrowNotAllowedException::class);
        $service->recordThrow($game, $dto);
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
            $this->createAccessService(),
            $this->createMock(GameRepositoryInterface::class),
        );

        $this->expectException(GameThrowNotAllowedException::class);
        $service->recordThrow($game, $dto);
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
            $this->createAccessService(),
            $this->createMock(GameRepositoryInterface::class),
        );

        $this->expectException(InvalidThrowException::class);
        $service->recordThrow($game, $dto);
    }

    /**
     * @throws ReflectionException
     */
    public function testRecordThrowThrowsWhenTripleBaseValueIsOutOfRange(): void
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
        $dto->value = 25;
        $dto->isDouble = false;
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
            $this->createAccessService(),
            $this->createMock(GameRepositoryInterface::class),
        );

        $this->expectException(InvalidThrowException::class);
        $service->recordThrow($game, $dto);
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
            $this->createAccessService(),
            $this->createMock(GameRepositoryInterface::class),
        );

        $this->expectException(GamePlayerNotActiveException::class);
        $service->recordThrow($game, $dto);
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
            $this->createAccessService(),
            $this->createMock(GameRepositoryInterface::class),
        );

        $this->expectException(GamePlayerNotActiveException::class);
        $service->recordThrow($game, $dto);
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
            $this->createAccessService(),
            $this->createMock(GameRepositoryInterface::class),
            $activePlayerResolver,
        );

        $service->recordThrow($game, $dto);

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
        $entityManager->expects(self::exactly(2))->method('flush');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->createAccessService(),
            $this->createMock(GameRepositoryInterface::class),
        );

        $result = $service->recordThrow($game, $dto);

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
        $roundThrowsRepository->method('findLatestForGameBeforeThrow')
            ->with(10, 103)
            ->willReturn($previousThrow);
        $roundThrowsRepository->method('findLatestForGameAndPlayerBeforeThrow')
            ->with(10, 1, 103)
            ->willReturn($previousThrow);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('wrapInTransaction')
            ->with(self::isInstanceOf(\Closure::class))
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $entityManager->expects(self::once())
            ->method('contains')
            ->with($game)
            ->willReturn(true);
        $entityManager->expects(self::once())
            ->method('lock')
            ->with($game, LockMode::PESSIMISTIC_WRITE);
        $entityManager->expects(self::once())->method('remove')->with($lastThrow);
        $entityManager->expects(self::once())->method('flush');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->createAccessService(),
            $this->createMock(GameRepositoryInterface::class),
        );

        $undoneThrow = $service->undoLastThrow($game);

        self::assertSame(GameStatus::Started, $game->getStatus());
        self::assertNull($game->getFinishedAt());
        self::assertSame(40, $winnerPlayer->getScore());
        self::assertSame(0, $winnerPlayer->getPosition());
        self::assertSame(0, $otherPlayer->getPosition());
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
        $latestGameThrowAfterUndo = (new RoundThrows())
            ->setGame($game)
            ->setRound($round)
            ->setPlayer($otherUser)
            ->setThrowId(399)
            ->setThrowNumber(1)
            ->setValue(20)
            ->setScore(160)
            ->setTimestamp(new DateTime());

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findEntityLatestForGame')
            ->with(20)
            ->willReturn($lastBustThrow);
        $roundThrowsRepository->method('findLatestForGameBeforeThrow')
            ->with(20, 401)
            ->willReturn($latestGameThrowAfterUndo);
        $roundThrowsRepository->method('findLatestForGameAndPlayerBeforeThrow')
            ->with(20, 1, 401)
            ->willReturn($previousPlayerThrow);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $entityManager->expects(self::once())
            ->method('contains')
            ->with($game)
            ->willReturn(true);
        $entityManager->expects(self::once())
            ->method('lock')
            ->with($game, LockMode::PESSIMISTIC_WRITE);
        $entityManager->expects(self::once())->method('remove')->with($lastBustThrow);
        $entityManager->expects(self::once())->method('flush');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->createAccessService(),
            $this->createMock(GameRepositoryInterface::class),
        );

        $undoneThrow = $service->undoLastThrow($game);

        self::assertNotNull($undoneThrow);
        self::assertTrue($undoneThrow->isBust);
        self::assertSame(181, $player->getScore());
        self::assertSame(0, $player->getPosition());
        self::assertSame(0, $otherPlayer->getPosition());
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
        $latestGameThrowAfterUndo = (new RoundThrows())
            ->setGame($game)
            ->setRound($round)
            ->setPlayer($secondUser)
            ->setThrowId(301)
            ->setThrowNumber(1)
            ->setValue(60)
            ->setScore(120)
            ->setTimestamp(new DateTime());

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findEntityLatestForGame')
            ->with(30)
            ->willReturn($winningThrow);
        $roundThrowsRepository->method('findLatestForGameBeforeThrow')
            ->with(30, 303)
            ->willReturn($latestGameThrowAfterUndo);
        $roundThrowsRepository->method('findLatestForGameAndPlayerBeforeThrow')
            ->with(30, 1, 303)
            ->willReturn($previousWinnerThrow);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $entityManager->expects(self::once())
            ->method('contains')
            ->with($game)
            ->willReturn(true);
        $entityManager->expects(self::once())
            ->method('lock')
            ->with($game, LockMode::PESSIMISTIC_WRITE);
        $entityManager->expects(self::once())->method('remove')->with($winningThrow);
        $entityManager->expects(self::once())->method('flush');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->createAccessService(),
            $this->createMock(GameRepositoryInterface::class),
        );

        $undoneThrow = $service->undoLastThrow($game);

        self::assertNotNull($undoneThrow);
        self::assertSame(40, $winnerPlayer->getScore());
        self::assertSame(0, $winnerPlayer->getPosition());
        self::assertSame(0, $secondPlayer->getPosition());
        self::assertSame(0, $thirdPlayer->getPosition());
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

        // Previous game throw: player 2's last throw in round 1 (different round!)
        $previousGameThrow = (new RoundThrows())
            ->setGame($game)
            ->setRound($round1)
            ->setPlayer($otherUser)
            ->setThrowId(200)
            ->setThrowNumber(3)
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
        $roundThrowsRepository->method('findLatestForGameBeforeThrow')
            ->with(40, 201)
            ->willReturn($previousGameThrow);
        $roundThrowsRepository->method('findLatestForGameAndPlayerBeforeThrow')
            ->with(40, 1, 201)
            ->willReturn($previousPlayerThrow);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $entityManager->expects(self::once())
            ->method('contains')
            ->with($game)
            ->willReturn(true);
        $entityManager->expects(self::once())
            ->method('lock')
            ->with($game, LockMode::PESSIMISTIC_WRITE);
        $entityManager->expects(self::once())->method('remove')->with($lastThrow);
        $entityManager->expects(self::once())->method('flush');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager,
            $this->createAccessService(),
            $this->createMock(GameRepositoryInterface::class),
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
            ->setPosition(4);

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
            $this->createAccessService(),
            $this->createMock(GameRepositoryInterface::class),
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
