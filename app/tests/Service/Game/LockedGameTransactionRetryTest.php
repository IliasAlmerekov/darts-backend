<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Entity\Game;
use App\Enum\GameStatus;
use App\Service\Game\LockedGameTransactionInterface;
use App\Tests\Support\TestUserFactory;
use DateTime;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use PDOException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class LockedGameTransactionRetryTest extends KernelTestCase
{
    public function testTheRetriedAttemptGetsTheGameManagedByTheResetEntityManager(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $admin = TestUserFactory::create($entityManager, 'lockedretry', 'admin', ['ROLE_ADMIN']);
        $game = (new Game())->setDate(new DateTime())->setStatus(GameStatus::Lobby);
        $entityManager->persist($game);
        $entityManager->flush();
        $gameId = (int) $game->getGameId();
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
        $deadlock = new DeadlockException(PdoDriverException::new(new PDOException('Deadlock found when trying to get lock')), null);
        $seen = [];

        $container->get(LockedGameTransactionInterface::class)->runWithRetry($gameId, static function (Game $locked) use (&$seen, $deadlock): void {
            $seen[] = $locked;
            if (1 === count($seen)) {
                throw $deadlock;
            }
        });

        self::assertCount(2, $seen);
        self::assertSame($gameId, $seen[1]->getGameId());
        // The reset replaced the entity manager: the first attempt's game is detached, the second is managed.
        self::assertNotSame($seen[0], $seen[1]);
        self::assertFalse($entityManager->contains($seen[0]));
        self::assertTrue($entityManager->contains($seen[1]));
    }
}
