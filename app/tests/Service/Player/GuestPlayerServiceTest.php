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
use App\Repository\UserRepositoryInterface;
use App\Service\Player\GuestPlayerService;
use App\Service\Player\PlayerManagementServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class GuestPlayerServiceTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepository;
    private PlayerManagementServiceInterface&MockObject $playerManagementService;
    private UserPasswordHasherInterface&MockObject $passwordHasher;
    private EntityManagerInterface&MockObject $entityManager;
    private GuestPlayerService $service;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->playerManagementService = $this->createMock(PlayerManagementServiceInterface::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->service = new GuestPlayerService(
            $this->userRepository,
            $this->playerManagementService,
            $this->passwordHasher,
            $this->entityManager,
        );
    }

    public function testCreateGuestPlayerCreatesUserAndAddsToGame(): void
    {
        $game = (new Game())->setGameId(10);

        $this->userRepository
            ->expects(self::once())
            ->method('findOneByUsername')
            ->with('Alex')
            ->willReturn(null);

        $this->passwordHasher
            ->expects(self::once())
            ->method('hashPassword')
            ->with(self::isInstanceOf(User::class), self::isType('string'))
            ->willReturn('hashed');

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(User::class))
            ->willReturnCallback(static function (User $user): void {
                (new \ReflectionProperty(User::class, 'id'))->setValue($user, 55);
            });

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        $gamePlayer = (new GamePlayers())->setPosition(2);
        $this->playerManagementService
            ->expects(self::once())
            ->method('addPlayer')
            ->with(10, 55)
            ->willReturn($gamePlayer);

        $result = $this->service->createGuestPlayer($game, 'Alex');

        self::assertSame(55, $result['playerId']);
        self::assertSame('Alex (Guest)', $result['name']);
        self::assertSame(2, $result['position']);
    }

    public function testCreateGuestPlayerThrowsWhenUsernameTaken(): void
    {
        $game = (new Game())->setGameId(10);

        $this->userRepository
            ->method('findOneByUsername')
            ->willReturnCallback(static function (string $username): ?User {
                if ('Alex' === $username) {
                    return new User();
                }

                return null;
            });

        try {
            $this->service->createGuestPlayer($game, 'Alex');
            self::fail('Expected exception was not thrown.');
        } catch (UsernameAlreadyTakenException $exception) {
            self::assertSame('Alex', $exception->getUsername());
            self::assertNotEmpty($exception->getSuggestions());
        }
    }
}
