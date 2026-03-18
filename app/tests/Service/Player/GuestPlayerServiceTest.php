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
use App\Exception\Game\GameIdMissingException;
use App\Exception\Request\UsernameAlreadyTakenException;
use App\Repository\GamePlayersRepositoryInterface;
use App\Service\Player\GuestPlayerService;
use App\Service\Player\PlayerManagementServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class GuestPlayerServiceTest extends TestCase
{
    public function testCreateGuestPlayerCreatesUserAndAddsToGame(): void
    {
        $game = (new Game())->setGameId(10);
        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $playerManagementService = $this->createMock(PlayerManagementServiceInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new GuestPlayerService(
            $gamePlayersRepository,
            $playerManagementService,
            $entityManager,
        );

        $gamePlayersRepository
            ->expects(self::once())
            ->method('existsNameInGame')
            ->with(10, 'Alex')
            ->willReturn(false);

        $gamePlayersRepository
            ->expects(self::never())
            ->method('findByGameId');

        $playerManagementService
            ->expects(self::once())
            ->method('addPlayerEntity')
            ->with(10, self::isInstanceOf(User::class), null, false)
            ->willReturn((new GamePlayers())->setPosition(2));

        $playerManagementService
            ->expects(self::never())
            ->method('addPlayer');

        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(User::class))
            ->willReturnCallback(static function (User $user): void {
                self::assertSame('Alex', $user->getDisplayNameRaw());
                self::assertTrue($user->isGuest());
                self::assertNotNull($user->getUsername());
                self::assertStringStartsWith('guest_', (string) $user->getUsername());
                (new \ReflectionProperty(User::class, 'id'))->setValue($user, 55);
            });

        $entityManager
            ->expects(self::once())
            ->method('flush');

        $result = $service->createGuestPlayer($game, 'Alex');

        self::assertSame(55, $result['playerId']);
        self::assertSame('Alex', $result['name']);
        self::assertSame(2, $result['position']);
        self::assertTrue($result['isGuest']);
    }

    public function testCreateGuestPlayerThrowsWhenUsernameTaken(): void
    {
        $game = (new Game())->setGameId(10);
        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $playerManagementService = $this->createMock(PlayerManagementServiceInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new GuestPlayerService(
            $gamePlayersRepository,
            $playerManagementService,
            $entityManager,
        );

        $gamePlayersRepository
            ->expects(self::once())
            ->method('existsNameInGame')
            ->with(10, 'Alex')
            ->willReturn(true);

        $gamePlayersRepository
            ->expects(self::never())
            ->method('findByGameId');

        $playerManagementService
            ->expects(self::never())
            ->method('addPlayerEntity');

        $entityManager
            ->expects(self::never())
            ->method('persist');

        $entityManager
            ->expects(self::never())
            ->method('flush');

        try {
            $service->createGuestPlayer($game, 'Alex');
            self::fail('Expected exception was not thrown.');
        } catch (UsernameAlreadyTakenException $exception) {
            self::assertSame('Alex', $exception->getUsername());
            self::assertSame([], $exception->getSuggestions());
        }
    }

    public function testCreateGuestPlayerTreatsExistingNameCaseInsensitively(): void
    {
        $game = (new Game())->setGameId(10);
        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $playerManagementService = $this->createMock(PlayerManagementServiceInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new GuestPlayerService(
            $gamePlayersRepository,
            $playerManagementService,
            $entityManager,
        );

        $gamePlayersRepository
            ->expects(self::once())
            ->method('existsNameInGame')
            ->with(10, 'Alex')
            ->willReturn(true);

        $gamePlayersRepository
            ->expects(self::never())
            ->method('findByGameId');

        $playerManagementService
            ->expects(self::never())
            ->method('addPlayerEntity');

        $entityManager
            ->expects(self::never())
            ->method('persist');

        $entityManager
            ->expects(self::never())
            ->method('flush');

        try {
            $service->createGuestPlayer($game, ' Alex ');
            self::fail('Expected exception was not thrown.');
        } catch (UsernameAlreadyTakenException $exception) {
            self::assertSame('Alex', $exception->getUsername());
        }
    }

    public function testCreateGuestPlayerThrowsBeforeWritesWhenGameIdMissing(): void
    {
        $game = new Game();
        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $playerManagementService = $this->createMock(PlayerManagementServiceInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new GuestPlayerService(
            $gamePlayersRepository,
            $playerManagementService,
            $entityManager,
        );

        $gamePlayersRepository
            ->expects(self::never())
            ->method('existsNameInGame');

        $playerManagementService
            ->expects(self::never())
            ->method('addPlayerEntity');

        $entityManager
            ->expects(self::never())
            ->method('persist');

        $entityManager
            ->expects(self::never())
            ->method('flush');

        try {
            $service->createGuestPlayer($game, 'Alex');
            self::fail('Expected exception was not thrown.');
        } catch (GameIdMissingException) {
            self::assertTrue(true);
        }
    }
}
