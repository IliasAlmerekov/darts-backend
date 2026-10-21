<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Entity\User;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\GameRepositoryInterface;
use App\Service\Game\GameRoomService;
use App\Service\Game\OpenLobby;
use App\Service\Game\OpenLobbyServiceInterface;
use App\Service\Player\PlayerManagementService;
use App\Service\Security\GameAccessServiceInterface;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
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
    private GameAccessServiceInterface $gameAccessService;
    private OpenLobbyServiceInterface&MockObject $openLobbyService;
    private Game $lobbyGame;

    protected function setUp(): void
    {
        $this->gameRepository = $this->createMock(GameRepositoryInterface::class);
        $this->gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $this->gamePlayersRepository->method('findBy')->willReturn([]);
        $this->gamePlayersRepository->method('findNextPositionForGame')->willReturn(1);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getReference')
            ->willReturnCallback(function (string $class, int $id): object {
                if (Game::class === $class) {
                    return (new Game())->setGameId($id);
                }

                if (User::class === $class) {
                    $user = new User();
                    $this->setPrivateProperty($user, 'id', $id);

                    return $user;
                }

                throw new \LogicException('Unexpected getReference call');
            });
        $this->playerManagementService = new PlayerManagementService(
            $this->gamePlayersRepository,
            $this->entityManager
        );
        $this->gameAccessService = $this->createMock(GameAccessServiceInterface::class);
        $this->gameAccessService->method('requireAuthenticatedUser')->willReturn(new User());
        $this->gameAccessService->method('assertPlayerInGameOrAdmin')->willReturn(new User());
        $this->lobbyGame = (new Game())->setGameId(99)->setDate(new DateTime());
        $this->openLobbyService = $this->createMock(OpenLobbyServiceInterface::class);
    }

    /**
     * @throws ORMException
     */
    public function testOpenRoomLeavesAnExistingLobbyUnchanged(): void
    {
        $this->openLobbyService->method('findOrCreate')->willReturn(new OpenLobby($this->lobbyGame, false));
        $this->gameRepository->expects(self::never())->method('find');
        $this->entityManager->expects(self::never())->method('persist');

        $service = $this->createService();

        $game = $service->openRoom([1, 2, 3], [2]);

        self::assertSame($this->lobbyGame, $game);
    }

    /**
     * @throws ORMException
     */
    public function testOpenRoomAddsPlayersToANewLobby(): void
    {
        $this->entityManager->expects(self::atLeast(1))->method('persist');
        $this->entityManager->expects(self::atLeast(1))->method('flush');

        $this->openLobbyService->method('findOrCreate')->willReturn(new OpenLobby($this->lobbyGame, true));
        $service = $this->createService();

        $game = $service->openRoom([1, 2, 3], [2]);

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
            $this->playerManagementService,
            $this->gameAccessService,
            $this->openLobbyService,
        );
    }
}
