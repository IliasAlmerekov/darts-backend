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
use App\Exception\Game\InvalidPlayerOrderException;
use App\Repository\GamePlayersRepositoryInterface;
use App\Service\Player\PlayerManagementService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PlayerManagementServiceTest extends TestCase
{
    private GamePlayersRepositoryInterface&MockObject $gamePlayersRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private PlayerManagementService $service;

    protected function setUp(): void
    {
        $this->gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->service = new PlayerManagementService($this->gamePlayersRepository, $this->entityManager);
    }

    public function testRemovePlayerReturnsFalseWhenNotFound(): void
    {
        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['game' => 1, 'player' => 2])
            ->willReturn(null);

        self::assertFalse($this->service->removePlayer(1, 2));
        $this->entityManager->expects(self::never())->method('remove');
    }

    public function testRemovePlayerDeletesEntity(): void
    {
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

    public function testAddPlayerCreatesAndPersistsGamePlayers(): void
    {
        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('findNextPositionForGame')
            ->with(100)
            ->willReturn(1);
        $this->entityManager
            ->expects(self::exactly(2))
            ->method('getReference')
            ->willReturnCallback(static function (string $class, int $id) {
                if ($class === Game::class) {
                    return (new Game())->setGameId($id);
                }
                if ($class === User::class) {
                    $user = new User();
                    (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
                    return $user;
                }

                throw new \LogicException('Unexpected getReference call');
            });

        $this->entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(GamePlayers::class));
        $this->entityManager->expects(self::once())->method('flush');

        $result = $this->service->addPlayer(100, 200);

        self::assertInstanceOf(GamePlayers::class, $result);
        self::assertSame(200, $result->getPlayer()?->getId());
        self::assertSame(100, $result->getGame()?->getGameId());
        self::assertSame(1, $result->getPosition());
    }

    public function testAddPlayerRespectsProvidedZeroBasedPosition(): void
    {
        $this->gamePlayersRepository
            ->expects(self::never())
            ->method('findNextPositionForGame');
        $this->entityManager
            ->expects(self::exactly(2))
            ->method('getReference')
            ->willReturnCallback(static function (string $class, int $id) {
                if ($class === Game::class) {
                    return (new Game())->setGameId($id);
                }
                if ($class === User::class) {
                    $user = new User();
                    (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

                    return $user;
                }

                throw new \LogicException('Unexpected getReference call');
            });

        $this->entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(GamePlayers::class));
        $this->entityManager->expects(self::once())->method('flush');

        $result = $this->service->addPlayer(100, 200, 0);

        self::assertSame(0, $result->getPosition());
    }

    public function testAddPlayerAssignsSequentialPositionWhenExistingPlayersHaveNullPositions(): void
    {
        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('findNextPositionForGame')
            ->with(300)
            ->willReturn(3);

        $this->entityManager
            ->expects(self::exactly(2))
            ->method('getReference')
            ->willReturnCallback(static function (string $class, int $id) {
                if ($class === Game::class) {
                    return (new Game())->setGameId($id);
                }
                if ($class === User::class) {
                    $user = new User();
                    (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

                    return $user;
                }

                throw new \LogicException('Unexpected getReference call');
            });

        $this->entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(GamePlayers::class));
        $this->entityManager->expects(self::once())->method('flush');

        $result = $this->service->addPlayer(300, 400);

        self::assertSame(3, $result->getPosition());
    }

    public function testAddPlayerUsesHighestExistingPositionWhenRepositoryOrderIsUnsorted(): void
    {
        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('findNextPositionForGame')
            ->with(301)
            ->willReturn(11);

        $this->entityManager
            ->expects(self::exactly(2))
            ->method('getReference')
            ->willReturnCallback(static function (string $class, int $id) {
                if ($class === Game::class) {
                    return (new Game())->setGameId($id);
                }
                if ($class === User::class) {
                    $user = new User();
                    (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

                    return $user;
                }

                throw new \LogicException('Unexpected getReference call');
            });

        $this->entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(GamePlayers::class));
        $this->entityManager->expects(self::once())->method('flush');

        $result = $this->service->addPlayer(301, 401);

        self::assertSame(11, $result->getPosition());
    }

    public function testAddPlayerEntityCanDeferFlush(): void
    {
        $player = $this->userWithId(402, 'guest_402', 'Guest 402');

        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('findNextPositionForGame')
            ->with(302)
            ->willReturn(4);

        $this->entityManager
            ->expects(self::once())
            ->method('getReference')
            ->with(Game::class, 302)
            ->willReturn((new Game())->setGameId(302));

        $this->entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(GamePlayers::class));
        $this->entityManager->expects(self::never())->method('flush');

        $result = $this->service->addPlayerEntity(302, $player, flush: false);

        self::assertSame(4, $result->getPosition());
        self::assertSame('Guest 402', $result->getDisplayNameSnapshot());
        self::assertSame(402, $result->getPlayer()?->getId());
    }

    public function testCopyPlayersCopiesOnlyFilteredPlayers(): void
    {
        $sourcePlayer1 = (new GamePlayers())->setPlayer($this->userWithId(1, 'alpha', 'Alpha'))->setPosition(2);
        $sourcePlayer2 = (new GamePlayers())->setPlayer($this->userWithId(2, 'beta'));
        $sourcePlayer3 = (new GamePlayers())->setPlayer($this->userWithId(3, 'gamma'))->setPosition(5);

        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('findByGameId')
            ->with(10)
            ->willReturn([$sourcePlayer1, $sourcePlayer2, $sourcePlayer3]);

        $persistedGamePlayers = [];
        $this->entityManager
            ->expects(self::exactly(2))
            ->method('getReference')
            ->willReturnCallback(function (string $class, int $id) {
                if ($class === Game::class) {
                    return (new Game())->setGameId($id);
                }
                throw new \LogicException('Unexpected getReference call');
            });

        $this->entityManager
            ->expects(self::exactly(2))
            ->method('persist')
            ->with(self::callback(function (GamePlayers $gp) use (&$persistedGamePlayers): bool {
                $persistedGamePlayers[] = $gp;

                return true;
            }));
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->copyPlayers(fromGameId: 10, toGameId: 20, playerIds: [1, 3]);

        self::assertCount(2, $persistedGamePlayers);
        self::assertSame([2, 5], array_map(static fn (GamePlayers $gamePlayer): ?int => $gamePlayer->getPosition(), $persistedGamePlayers));
        self::assertSame(['Alpha', 'gamma'], array_map(static fn (GamePlayers $gamePlayer): ?string => $gamePlayer->getDisplayNameSnapshot(), $persistedGamePlayers));
    }

    public function testUpdatePlayerPositionsUpdatesExistingPlayers(): void
    {
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

    public function testUpdatePlayerPositionsThrowsOnDuplicatePlayerIds(): void
    {
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
