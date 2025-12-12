<?php

declare(strict_types=1);

namespace App\Tests\Service\Player;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
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
        $this->entityManager
            ->expects(self::exactly(2))
            ->method('getReference')
            ->willReturnCallback(static function (string $class, int $id) {
                if ($class === Game::class) {
                    return (new Game())->setGameId($id);
                }
                if ($class === User::class) {
                    $user = new User();
                    $ref = new \ReflectionProperty(User::class, 'id');
                    $ref->setAccessible(true);
                    $ref->setValue($user, $id);

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
    }

    public function testCopyPlayersCopiesOnlyFilteredPlayers(): void
    {
        $sourcePlayer1 = (new GamePlayers())->setPlayer($this->userWithId(1));
        $sourcePlayer2 = (new GamePlayers())->setPlayer($this->userWithId(2));
        $sourcePlayer3 = (new GamePlayers())->setPlayer($this->userWithId(3));

        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('findByGameId')
            ->with(10)
            ->willReturn([$sourcePlayer1, $sourcePlayer2, $sourcePlayer3]);

        $this->entityManager
            ->expects(self::exactly(4))
            ->method('getReference')
            ->willReturnCallback(function (string $class, int $id) {
                if ($class === Game::class) {
                    return (new Game())->setGameId($id);
                }
                if ($class === User::class) {
                    return $this->userWithId($id);
                }
                throw new \LogicException('Unexpected getReference call');
            });

        $this->entityManager->expects(self::exactly(2))->method('persist')->with(self::isInstanceOf(GamePlayers::class));
        $this->entityManager->expects(self::exactly(2))->method('flush');

        $this->service->copyPlayers(fromGameId: 10, toGameId: 20, playerIds: [1, 3]);
    }

    private function userWithId(int $id): User
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($user, $id);

        return $user;
    }
}
