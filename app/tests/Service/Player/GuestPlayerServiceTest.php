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
use App\Exception\Request\UsernameAlreadyTakenException;
use App\Repository\GamePlayersRepositoryInterface;
use App\Service\Player\GuestPlayerService;
use App\Service\Player\PlayerManagementServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class GuestPlayerServiceTest extends TestCase
{
    public function testCreateGuestPlayerCreatesUserAndAddsToGame(): void
    {
        $game = (new Game())->setGameId(10);
        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $playerManagementService = $this->createMock(PlayerManagementServiceInterface::class);
        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new GuestPlayerService(
            $gamePlayersRepository,
            $playerManagementService,
            $passwordHasher,
            $entityManager,
        );

        $gamePlayersRepository
            ->expects(self::once())
            ->method('findByGameId')
            ->with(10)
            ->willReturn([]);

        $passwordHasher
            ->expects(self::once())
            ->method('hashPassword')
            ->with(self::isInstanceOf(User::class), self::isString())
            ->willReturn('hashed');

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

        $gamePlayer = (new GamePlayers())->setPosition(2);
        $playerManagementService
            ->expects(self::once())
            ->method('addPlayer')
            ->with(10, 55)
            ->willReturn($gamePlayer);

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
        $service = new GuestPlayerService(
            $gamePlayersRepository,
            $this->createStub(PlayerManagementServiceInterface::class),
            $this->createStub(UserPasswordHasherInterface::class),
            $this->createStub(EntityManagerInterface::class),
        );

        $existingPlayer = new GamePlayers();
        $existingPlayer->setDisplayNameSnapshot('Alex');
        $gamePlayersRepository
            ->expects(self::once())
            ->method('findByGameId')
            ->with(10)
            ->willReturn([$existingPlayer]);

        try {
            $service->createGuestPlayer($game, 'Alex');
            self::fail('Expected exception was not thrown.');
        } catch (UsernameAlreadyTakenException $exception) {
            self::assertSame('Alex', $exception->getUsername());
            self::assertSame([], $exception->getSuggestions());
        }
    }
}
