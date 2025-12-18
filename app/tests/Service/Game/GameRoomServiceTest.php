<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\GameRepositoryInterface;
use App\Service\Game\GameRoomService;
use App\Service\Player\PlayerManagementService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionProperty;

#[AllowMockObjectsWithoutExpectations]
final class GameRoomServiceTest extends TestCase
{
    private GameRepositoryInterface $gameRepository;
    private GamePlayersRepositoryInterface $gamePlayersRepository;
    private EntityManagerInterface $entityManager;
    private PlayerManagementService $playerManagementService;

    protected function setUp(): void
    {
        $this->gameRepository = $this->createMock(GameRepositoryInterface::class);
        $this->gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $this->gamePlayersRepository->method('findBy')->willReturn([]);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->playerManagementService = new PlayerManagementService(
            $this->gamePlayersRepository,
            $this->entityManager
        );
    }

    public function testCreateGamePersistsAndSetsDate(): void
    {
        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $service = $this->createService();

        $game = $service->createGame();

        self::assertInstanceOf(DateTime::class, $game->getDate());
    }

    /**
     * @throws ReflectionException
     * @throws ORMException
     */
    public function testCreateGameWithPreviousPlayersCopiesPlayers(): void
    {
        $previousGame = new Game();
        $this->setPrivateProperty($previousGame, 'gameId', 10);

        $this->gameRepository->method('find')
            ->with(10)
            ->willReturn($previousGame);

        $this->gamePlayersRepository->method('findByGameId')
            ->with(10)
            ->willReturn([
                $this->createGamePlayerWithUserId(1),
                $this->createGamePlayerWithUserId(2),
                $this->createGamePlayerWithUserId(3),
            ]);

        $this->entityManager->expects(self::atLeast(1))->method('persist');
        $this->entityManager->expects(self::atLeast(1))->method('flush');

        $service = $this->createService();

        $game = $service->createGameWithPreviousPlayers(10, [1, 2, 3], [2]);

        self::assertInstanceOf(DateTime::class, $game->getDate());
    }

    /**
     * @throws ORMException
     */
    public function testCreateGameWithNewPlayersAddsThemWhenNoPreviousGame(): void
    {
        $this->entityManager->expects(self::atLeast(1))->method('persist');
        $this->entityManager->expects(self::atLeast(1))->method('flush');

        $service = $this->createService();

        $game = $service->createGameWithPreviousPlayers(null, [1, 2, 3], [2]);

        self::assertInstanceOf(DateTime::class, $game->getDate());
    }

    public function testFindGameByIdReturnsGame(): void
    {
        $game = new Game();
        $this->gameRepository->method('find')
            ->with(42)
            ->willReturn($game);

        $service = $this->createService();

        $result = $service->findGameById(42);

        self::assertSame($game, $result);
    }

    public function testFindGameByIdReturnsNullWhenNotFound(): void
    {
        $this->gameRepository->method('find')
            ->with(42)
            ->willReturn(null);

        $service = $this->createService();

        $result = $service->findGameById(42);

        self::assertNull($result);
    }

    public function testGetPlayersWithUserInfoDelegatesToRepository(): void
    {
        $players = [['id' => 1, 'username' => 'Player 1']];
        $this->gamePlayersRepository->method('findPlayersWithUserInfo')
            ->with(42)
            ->willReturn($players);

        $service = $this->createService();

        $result = $service->getPlayersWithUserInfo(42);

        self::assertSame($players, $result);
    }

    /**
     * @throws ReflectionException
     */
    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty($object, $property);
        $ref->setValue($object, $value);
    }

    private function createService(): GameRoomService
    {
        return new GameRoomService(
            $this->gameRepository,
            $this->gamePlayersRepository,
            $this->entityManager,
            $this->playerManagementService
        );
    }

    /**
     * @throws ReflectionException
     */
    private function createGamePlayerWithUserId(int $userId): GamePlayers
    {
        $user = new User();
        $this->setPrivateProperty($user, 'id', $userId);

        $gamePlayer = new GamePlayers();
        $gamePlayer->setPlayer($user);

        return $gamePlayer;
    }
}
