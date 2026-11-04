<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service\Player;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Exception\Game\GameNotFoundException;
use App\Exception\Game\GameRoomFullException;
use App\Exception\Game\InvalidPlayerOrderException;
use App\Exception\Game\PlayerNotFoundException;
use App\Exception\Game\SeatsNotEditableException;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\GameRepositoryInterface;
use App\Repository\UserRepositoryInterface;
use App\Service\Player\PlayerManagementService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class PlayerManagementServiceTest extends TestCase
{
    private GamePlayersRepositoryInterface&MockObject $gamePlayersRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private UserRepositoryInterface&MockObject $userRepository;
    private ?Game $lockedGame = null;
    private PlayerManagementService $service;
    /** @var list<object> */
    private array $persisted = [];
    private bool $callerTransaction = false;
    private int $ownTransactions = 0;

    protected function setUp(): void
    {
        $this->gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('wrapInTransaction')->willReturnCallback(function (callable $func): mixed {
            $this->ownTransactions++;

            return $func();
        });
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturnCallback(fn (): bool => $this->callerTransaction);
        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
        $this->entityManager->method('getReference')->willReturnCallback(fn (string $class, int $id): User => User::class === $class
            ? $this->userWithId($id, 'user'.$id)
            : throw new \LogicException('Unexpected getReference call'));
        $gameRepository = $this->createStub(GameRepositoryInterface::class);
        $gameRepository->method('findOneByGameIdWithSeatsForUpdate')->willReturnCallback(fn (int $gameId): ?Game => $this->lockedGame);
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->service = new PlayerManagementService($this->gamePlayersRepository, $this->entityManager, $gameRepository, $this->userRepository);
    }

    public function testAddPlayerSeatsThePlayerAfterTheLockedSeats(): void
    {
        $game = $this->lockGame(GameStatus::Lobby, [1 => 1, 2 => 2]);

        $this->service->addPlayer(100, 200);

        $seat = $this->persistedSeats()[0];
        self::assertSame(200, $seat->getPlayer()?->getId());
        self::assertSame(3, $seat->getPosition());
        self::assertSame('user200', $seat->getDisplayNameSnapshot());
        self::assertSame($game, $seat->getGame());
        self::assertCount(3, $game->getGamePlayers());
    }

    /**
     * @return iterable<string, array{array<int, int|null>, int}> locked seats (player id => position), expected position
     */
    public static function nextPositionCases(): iterable
    {
        yield 'empty game' => [[], 1];
        yield 'seats without positions count' => [[1 => null, 2 => null], 3];
        yield 'highest position, not the last seat' => [[1 => 10, 2 => 3], 11];
        yield 'zero-based seats' => [[1 => 0], 1];
    }

    /**
     * @param array<int, int|null> $seats
     * @param int                  $expected
     */
    #[DataProvider('nextPositionCases')]
    public function testANewSeatTakesThePositionAfterTheHighestOne(array $seats, int $expected): void
    {
        $this->lockGame(GameStatus::Lobby, $seats);

        $this->service->addPlayer(100, 200);

        self::assertSame($expected, $this->persistedSeats()[0]->getPosition());
    }

    public function testAddPlayerToAStartedGameThrowsAndSeatsNobody(): void
    {
        $this->lockGame(GameStatus::Started, [1 => 1]);

        try {
            $this->service->addPlayer(7, 200);
            self::fail('A player was seated in a started game.');
        } catch (SeatsNotEditableException $exception) {
            self::assertSame(GameStatus::Started, $exception->status);
        }

        self::assertSame([], $this->persisted);
    }

    public function testAddPlayerKeepsTheSeatOfAPlayerWhoAlreadySitsInAStartedGame(): void
    {
        $this->lockGame(GameStatus::Started, [200 => 1]);

        $this->service->addPlayer(7, 200);

        self::assertSame([], $this->persisted);
    }

    public function testAddPlayerToAFullGameThrowsAndSeatsNobody(): void
    {
        $this->lockGame(GameStatus::Lobby, array_fill(1, Game::MAX_PARTICIPANTS, null));

        $this->expectException(GameRoomFullException::class);

        try {
            $this->service->addPlayer(7, 200);
        } finally {
            self::assertSame([], $this->persisted);
        }
    }

    public function testASeatWriteWithoutACallerTransactionOpensItsOwn(): void
    {
        $this->lockGame(GameStatus::Lobby, []);

        $this->service->addPlayer(100, 200);

        self::assertSame(1, $this->ownTransactions);
        self::assertCount(1, $this->persistedSeats());
    }

    public function testASeatWriteInTheCallersTransactionFlushesWithoutASavepoint(): void
    {
        // Room create and rematch: RetryingTransaction already holds a transaction.
        $this->callerTransaction = true;
        $this->lockGame(GameStatus::Lobby, []);
        $this->userRepository->method('findByIds')->willReturn([$this->userWithId(200, 'anna')]);
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->addPlayers(100, [200]);

        self::assertSame(0, $this->ownTransactions);
        self::assertCount(1, $this->persistedSeats());
    }

    public function testAddPlayerToAMissingGameThrows(): void
    {
        $this->expectException(GameNotFoundException::class);

        $this->service->addPlayer(999, 200);
    }

    public function testAddNewPlayerPersistsThePlayerWithItsSeat(): void
    {
        $game = $this->lockGame(GameStatus::Lobby, [1 => 1]);
        $guest = $this->userWithId(402, 'guest_402', 'Guest 402');

        $seat = $this->service->addNewPlayer(302, static fn (): User => $guest);

        self::assertSame([$guest, $seat], $this->persisted);
        self::assertSame(2, $seat->getPosition());
        self::assertSame('Guest 402', $seat->getDisplayNameSnapshot());
        self::assertSame($guest, $seat->getPlayer());
        self::assertTrue($game->getGamePlayers()->contains($seat));
    }

    /**
     * @return iterable<string, array{GameStatus, int, class-string<\Throwable>}>
     */
    public static function rejectedNewPlayerCases(): iterable
    {
        yield 'started game' => [GameStatus::Started, 1, SeatsNotEditableException::class];
        yield 'full lobby' => [GameStatus::Lobby, Game::MAX_PARTICIPANTS, GameRoomFullException::class];
    }

    /**
     * @param GameStatus                $status
     * @param int                       $seats
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('rejectedNewPlayerCases')]
    public function testAddNewPlayerRejectsTheGameBeforeItCreatesThePlayer(GameStatus $status, int $seats, string $exception): void
    {
        $this->lockGame($status, array_fill(1, $seats, null));
        $created = false;

        try {
            $this->service->addNewPlayer(302, function () use (&$created): User {
                $created = true;

                return new User();
            });
            self::fail('The new player was seated.');
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($exception, $thrown);
        }

        self::assertFalse($created);
        self::assertSame([], $this->persisted);
    }

    public function testAddNewPlayerSeatsNobodyWhenTheClosureRejectsThePlayer(): void
    {
        $this->lockGame(GameStatus::Lobby, []);

        try {
            $this->service->addNewPlayer(302, static fn (): User => throw new \DomainException('name taken'));
            self::fail('The closure did not reject the player.');
        } catch (\DomainException) {
        }

        self::assertSame([], $this->persisted);
    }

    /**
     * @return iterable<string, array{string, string|null, string}> username, display name, snapshot
     */
    public static function snapshotCases(): iterable
    {
        yield 'a blank display name falls back to the trimmed username' => [' tom ', '   ', 'tom'];
        yield 'the spaces around a display name are trimmed' => ['tom', '  Tommy  ', 'Tommy'];
        yield 'a user without any name stays empty instead of Player N' => ['   ', null, ''];
    }

    #[DataProvider('snapshotCases')]
    public function testTheSeatSnapshotsTheDisplayName(string $username, ?string $displayName, string $snapshot): void
    {
        $this->lockGame(GameStatus::Lobby, []);
        $player = $this->userWithId(403, $username, $displayName);

        $seat = $this->service->addNewPlayer(303, static fn (): User => $player);

        self::assertSame($snapshot, $seat->getDisplayNameSnapshot());
    }

    public function testAddPlayersSeatsThePlayersInTheGivenOrder(): void
    {
        $game = $this->lockGame(GameStatus::Lobby, []);
        $this->usersFound([$this->userWithId(1, 'alpha'), $this->userWithId(2, 'beta')]);

        $this->service->addPlayers(20, [2, 1]);

        self::assertSame([[2, 1], [1, 2]], $this->seatRows($this->persistedSeats()));
        self::assertCount(2, $game->getGamePlayers());
    }

    public function testAddPlayersRejectsMorePlayersThanFreeSeatsBeforeLookingThemUp(): void
    {
        $this->lockGame(GameStatus::Lobby, [100 => 1]);
        $this->userRepository->expects(self::never())->method('findByIds');

        $this->expectException(GameRoomFullException::class);

        try {
            $this->service->addPlayers(20, range(1, Game::MAX_PARTICIPANTS));
        } finally {
            self::assertSame([], $this->persisted);
        }
    }

    public function testAddPlayersToAStartedGameThrows(): void
    {
        $this->lockGame(GameStatus::Started, []);

        $this->expectException(SeatsNotEditableException::class);

        $this->service->addPlayers(20, [1]);
    }

    public function testAddPlayersWithAnUnknownIdThrowsAndSeatsNobody(): void
    {
        $game = $this->lockGame(GameStatus::Lobby, []);
        $this->usersFound([$this->userWithId(1, 'alpha')]);

        $this->expectException(PlayerNotFoundException::class);

        try {
            $this->service->addPlayers(20, [1, 2]);
        } finally {
            self::assertSame([], $this->persisted);
            self::assertCount(0, $game->getGamePlayers());
        }
    }

    public function testCopyPlayersCopiesEveryPlayerAndFillsMissingPositions(): void
    {
        $targetGame = $this->lockGame(GameStatus::Lobby, []);
        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('findByGameId')
            ->with(10)
            ->willReturn([
                (new GamePlayers())->setPlayer($this->userWithId(1, 'alpha', 'Alpha'))->setPosition(2),
                (new GamePlayers())->setPlayer($this->userWithId(2, 'beta')),
                (new GamePlayers())->setPlayer($this->userWithId(3, 'gamma'))->setPosition(5),
            ]);

        $this->service->copyPlayers(fromGameId: 10, toGameId: 20);

        $seats = $this->persistedSeats();
        self::assertSame([[1, 2], [2, 3], [3, 5]], $this->seatRows($seats));
        self::assertSame(['Alpha', 'beta', 'gamma'], array_map(static fn (GamePlayers $seat): ?string => $seat->getDisplayNameSnapshot(), $seats));
        self::assertSame($seats, $targetGame->getGamePlayers()->toArray());
    }

    public function testCopyPlayersIntoAStartedGameThrows(): void
    {
        $this->lockGame(GameStatus::Started, []);
        $this->gamePlayersRepository->expects(self::never())->method('findByGameId');

        $this->expectException(SeatsNotEditableException::class);

        $this->service->copyPlayers(10, 20);
    }

    public function testCopyPlayersSkipsThePlayersBeyondTheSeatLimit(): void
    {
        $this->lockGame(GameStatus::Lobby, []);
        $this->oldSeats(Game::MAX_PARTICIPANTS + 1);

        $this->service->copyPlayers(10, 20);

        self::assertCount(Game::MAX_PARTICIPANTS, $this->persistedSeats());
    }

    public function testAppendMissingPlayersSeatsOnlyTheMissingAfterTheLockedSeats(): void
    {
        $this->lockGame(GameStatus::Lobby, [1 => 1]);
        $this->oldSeats(2);

        $this->service->appendMissingPlayers(10, 20);

        self::assertSame([[2, 2]], $this->seatRows($this->persistedSeats()));
    }

    public function testAppendMissingPlayersStopsAtTheSeatLimit(): void
    {
        $this->lockGame(GameStatus::Lobby, array_fill(100, Game::MAX_PARTICIPANTS - 1, 1));
        $this->oldSeats(3);

        $this->service->appendMissingPlayers(10, 20);

        self::assertSame([1], array_map(static fn (GamePlayers $seat): ?int => $seat->getPlayer()?->getId(), $this->persistedSeats()));
    }

    public function testAppendMissingPlayersIntoAStartedGameThrows(): void
    {
        $this->lockGame(GameStatus::Started, []);

        $this->expectException(SeatsNotEditableException::class);

        $this->service->appendMissingPlayers(10, 20);
    }

    public function testRemovePlayerReturnsFalseWhenNotFound(): void
    {
        $this->lockGame(GameStatus::Lobby, []);
        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['game' => 1, 'player' => 2])
            ->willReturn(null);
        $this->entityManager->expects(self::never())->method('remove');

        self::assertFalse($this->service->removePlayer(1, 2));
    }

    public function testRemovePlayerFromAMissingGameThrowsAndRemovesNothing(): void
    {
        $this->gamePlayersRepository->expects(self::never())->method('findOneBy');
        $this->entityManager->expects(self::never())->method('remove');

        $this->expectException(GameNotFoundException::class);

        $this->service->removePlayer(999, 2);
    }

    public function testRemovePlayerFromAStartedGameThrows(): void
    {
        $this->lockGame(GameStatus::Started, [5 => 1]);
        $this->entityManager->expects(self::never())->method('remove');

        $this->expectException(SeatsNotEditableException::class);

        $this->service->removePlayer(10, 5);
    }

    public function testRemovePlayerDeletesEntity(): void
    {
        $this->lockGame(GameStatus::Lobby, []);
        $gamePlayer = new GamePlayers();
        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['game' => 10, 'player' => 5])
            ->willReturn($gamePlayer);

        $this->entityManager->expects(self::once())->method('remove')->with($gamePlayer);
        $this->entityManager->expects(self::once())->method('flush');

        self::assertTrue($this->service->removePlayer(10, 5));
    }

    public function testUpdatePlayerPositionsUpdatesExistingPlayers(): void
    {
        $this->lockGame(GameStatus::Lobby, []);
        $playerOne = (new GamePlayers())->setPlayer($this->userWithId(1))->setPosition(1);
        $playerTwo = (new GamePlayers())->setPlayer($this->userWithId(2))->setPosition(2);

        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('findBy')
            ->with(['game' => 42])
            ->willReturn([$playerOne, $playerTwo]);

        $this->entityManager->expects(self::once())->method('flush');

        $this->service->updatePlayerPositions(42, [
            ['playerId' => 2, 'position' => 1],
            ['playerId' => 1, 'position' => 2],
        ]);

        self::assertSame(2, $playerOne->getPosition());
        self::assertSame(1, $playerTwo->getPosition());
    }

    public function testUpdatePlayerPositionsOfAStartedGameThrows(): void
    {
        $this->lockGame(GameStatus::Started, []);
        $this->gamePlayersRepository->expects(self::never())->method('findBy');

        $this->expectException(SeatsNotEditableException::class);

        $this->service->updatePlayerPositions(42, [['playerId' => 1, 'position' => 1]]);
    }

    public function testUpdatePlayerPositionsThrowsOnDuplicatePlayerIds(): void
    {
        $this->lockGame(GameStatus::Lobby, []);
        $this->gamePlayersRepository->expects(self::never())->method('findBy');
        $this->entityManager->expects(self::never())->method('flush');

        $this->expectException(InvalidPlayerOrderException::class);

        $this->service->updatePlayerPositions(42, [
            ['playerId' => 2, 'position' => 1],
            ['playerId' => 2, 'position' => 2],
        ]);
    }

    public function testUpdatePlayerPositionsThrowsOnDuplicatePositions(): void
    {
        $this->lockGame(GameStatus::Lobby, []);
        $this->gamePlayersRepository->expects(self::never())->method('findBy');
        $this->entityManager->expects(self::never())->method('flush');

        $this->expectException(InvalidPlayerOrderException::class);

        $this->service->updatePlayerPositions(42, [
            ['playerId' => 2, 'position' => 1],
            ['playerId' => 3, 'position' => 1],
        ]);
    }

    public function testUpdatePlayerPositionsThrowsWhenPartialUpdateCreatesDuplicateWithExistingPosition(): void
    {
        $this->lockGame(GameStatus::Lobby, []);
        $playerOne = (new GamePlayers())->setPlayer($this->userWithId(1))->setPosition(1);
        $playerTwo = (new GamePlayers())->setPlayer($this->userWithId(2))->setPosition(2);

        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('findBy')
            ->with(['game' => 42])
            ->willReturn([$playerOne, $playerTwo]);

        $this->entityManager->expects(self::never())->method('flush');

        $this->expectException(InvalidPlayerOrderException::class);

        $this->service->updatePlayerPositions(42, [
            ['playerId' => 2, 'position' => 1],
        ]);
    }

    public function testUpdatePlayerPositionsThrowsWhenPayloadDoesNotIncludeAllPlayers(): void
    {
        $this->lockGame(GameStatus::Lobby, []);
        $playerOne = (new GamePlayers())->setPlayer($this->userWithId(1))->setPosition(1);
        $playerTwo = (new GamePlayers())->setPlayer($this->userWithId(2))->setPosition(2);

        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('findBy')
            ->with(['game' => 42])
            ->willReturn([$playerOne, $playerTwo]);

        $this->entityManager->expects(self::never())->method('flush');

        $this->expectException(InvalidPlayerOrderException::class);

        $this->service->updatePlayerPositions(42, [
            ['playerId' => 1, 'position' => 1],
        ]);
    }

    public function testUpdatePlayerPositionsThrowsWhenPayloadContainsUnknownPlayer(): void
    {
        $this->lockGame(GameStatus::Lobby, []);
        $playerOne = (new GamePlayers())->setPlayer($this->userWithId(1))->setPosition(1);
        $playerTwo = (new GamePlayers())->setPlayer($this->userWithId(2))->setPosition(2);

        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('findBy')
            ->with(['game' => 42])
            ->willReturn([$playerOne, $playerTwo]);

        $this->entityManager->expects(self::never())->method('flush');

        $this->expectException(InvalidPlayerOrderException::class);

        $this->service->updatePlayerPositions(42, [
            ['playerId' => 1, 'position' => 1],
            ['playerId' => 3, 'position' => 2],
        ]);
    }

    public function testUpdatePlayerPositionsThrowsWhenPositionsAreNotContiguous(): void
    {
        $this->lockGame(GameStatus::Lobby, []);
        $playerOne = (new GamePlayers())->setPlayer($this->userWithId(1))->setPosition(1);
        $playerTwo = (new GamePlayers())->setPlayer($this->userWithId(2))->setPosition(2);
        $playerThree = (new GamePlayers())->setPlayer($this->userWithId(3))->setPosition(3);

        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('findBy')
            ->with(['game' => 42])
            ->willReturn([$playerOne, $playerTwo, $playerThree]);

        $this->entityManager->expects(self::never())->method('flush');

        $this->expectException(InvalidPlayerOrderException::class);

        $this->service->updatePlayerPositions(42, [
            ['playerId' => 1, 'position' => 1],
            ['playerId' => 2, 'position' => 3],
            ['playerId' => 3, 'position' => 4],
        ]);
    }

    /**
     * The game the locking read returns, with one seat per entry.
     *
     * @param GameStatus           $status
     * @param array<int, int|null> $seats player id => position
     *
     * @return Game
     */
    private function lockGame(GameStatus $status, array $seats): Game
    {
        $game = (new Game())->setGameId(1)->setStatus($status);
        foreach ($seats as $playerId => $position) {
            $seat = (new GamePlayers())->setPlayer($this->userWithId($playerId));
            if (null !== $position) {
                $seat->setPosition($position);
            }
            $game->addGamePlayer($seat);
        }

        return $this->lockedGame = $game;
    }

    /**
     * The old game of a rematch, with players 1 to $count at positions 1 to $count.
     *
     * @param int $count
     *
     * @return void
     */
    private function oldSeats(int $count): void
    {
        $seats = [];
        for ($playerId = 1; $playerId <= $count; $playerId++) {
            $seats[] = (new GamePlayers())->setPlayer($this->userWithId($playerId, 'player'.$playerId))->setPosition($playerId);
        }
        $this->gamePlayersRepository->method('findByGameId')->willReturn($seats);
    }

    /**
     * @param list<User> $users
     *
     * @return void
     */
    private function usersFound(array $users): void
    {
        $this->userRepository->method('findByIds')->willReturn($users);
    }

    /**
     * @return list<GamePlayers>
     */
    private function persistedSeats(): array
    {
        return array_values(array_filter($this->persisted, static fn (object $entity): bool => $entity instanceof GamePlayers));
    }

    /**
     * @param list<GamePlayers> $seats
     *
     * @return list<array{int|null, int|null}> player id and position per seat
     */
    private function seatRows(array $seats): array
    {
        return array_map(static fn (GamePlayers $seat): array => [$seat->getPlayer()?->getId(), $seat->getPosition()], $seats);
    }

    private function userWithId(int $id, ?string $username = null, ?string $displayName = null): User
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);
        if (null !== $username) {
            $user->setUsername($username);
        }

        if (null !== $displayName) {
            $user->setDisplayName($displayName);
        }

        return $user;
    }
}
