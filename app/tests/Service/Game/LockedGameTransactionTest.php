<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Entity\Game;
use App\Entity\User;
use App\Exception\Game\GameNotFoundException;
use App\Repository\GameRepositoryInterface;
use App\Service\Game\LockedGameTransaction;
use App\Service\Persistence\RetryingTransaction;
use App\Service\Persistence\RetryingTransactionInterface;
use App\Service\Security\GameAccessServiceInterface;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PDOException;
use PHPUnit\Framework\TestCase;

final class LockedGameTransactionTest extends TestCase
{
    public function testRunChecksAdminThenOpensTheTransactionThenLocksTheGameThenRunsTheOperation(): void
    {
        $steps = [];
        $game = (new Game())->setGameId(7);
        $access = $this->createMock(GameAccessServiceInterface::class);
        $access->expects(self::once())->method('assertAdmin')->willReturnCallback(static function () use (&$steps): User {
            $steps[] = 'admin';

            return new User();
        });
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('wrapInTransaction')->willReturnCallback(static function (callable $operation) use (&$steps): mixed {
            $steps[] = 'transaction';

            return $operation();
        });
        $games = $this->createMock(GameRepositoryInterface::class);
        $games->expects(self::once())->method('findOneByGameIdWithSeatsForUpdate')->with(7)->willReturnCallback(static function () use (&$steps, $game): Game {
            $steps[] = 'lock';

            return $game;
        });
        $retrying = $this->createMock(RetryingTransactionInterface::class);
        $retrying->expects(self::never())->method('run');

        $result = (new LockedGameTransaction($access, $entityManager, $retrying, $games))->run(7, static function (Game $locked) use (&$steps, $game): string {
            $steps[] = 'operation';
            self::assertSame($game, $locked);

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(['admin', 'transaction', 'lock', 'operation'], $steps);
    }

    public function testRunWithRetryChecksAdminThenOpensTheRetryingTransactionThenLocksTheGameThenRunsTheOperation(): void
    {
        $steps = [];
        $game = (new Game())->setGameId(7);
        $access = $this->createMock(GameAccessServiceInterface::class);
        $access->expects(self::once())->method('assertAdmin')->willReturnCallback(static function () use (&$steps): User {
            $steps[] = 'admin';

            return new User();
        });
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('wrapInTransaction');
        $retrying = $this->createMock(RetryingTransactionInterface::class);
        $retrying->expects(self::once())->method('run')->willReturnCallback(static function (callable $operation) use (&$steps): mixed {
            $steps[] = 'transaction';

            return $operation();
        });
        $games = $this->createMock(GameRepositoryInterface::class);
        $games->expects(self::once())->method('findOneByGameIdWithSeatsForUpdate')->with(7)->willReturnCallback(static function () use (&$steps, $game): Game {
            $steps[] = 'lock';

            return $game;
        });

        $result = (new LockedGameTransaction($access, $entityManager, $retrying, $games))->runWithRetry(7, static function (Game $locked) use (&$steps, $game): string {
            $steps[] = 'operation';
            self::assertSame($game, $locked);

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(['admin', 'transaction', 'lock', 'operation'], $steps);
    }

    public function testRunThrowsGameNotFoundWithoutRunningTheOperationWhenTheGameIsMissing(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $games = $this->createStub(GameRepositoryInterface::class);
        $games->method('findOneByGameIdWithSeatsForUpdate')->willReturn(null);
        $transaction = new LockedGameTransaction($this->createStub(GameAccessServiceInterface::class), $entityManager, $this->createStub(RetryingTransactionInterface::class), $games);

        $this->expectException(GameNotFoundException::class);
        $transaction->run(7, static fn (Game $game): never => self::fail('The operation ran without a game.'));
    }

    public function testRunWithRetryThrowsGameNotFoundWithoutRunningTheOperationWhenTheGameIsMissing(): void
    {
        $retrying = $this->createStub(RetryingTransactionInterface::class);
        $retrying->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $games = $this->createStub(GameRepositoryInterface::class);
        $games->method('findOneByGameIdWithSeatsForUpdate')->willReturn(null);
        $transaction = new LockedGameTransaction($this->createStub(GameAccessServiceInterface::class), $this->createStub(EntityManagerInterface::class), $retrying, $games);

        $this->expectException(GameNotFoundException::class);
        $transaction->runWithRetry(7, static fn (Game $game): never => self::fail('The operation ran without a game.'));
    }

    public function testRunWithRetryReloadsTheGameByIdForTheSecondAttemptAfterADeadlock(): void
    {
        $detached = (new Game())->setGameId(7);
        $reloaded = (new Game())->setGameId(7);
        $deadlock = new DeadlockException(PdoDriverException::new(new PDOException('Deadlock found when trying to get lock')), null);
        $attempts = 0;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('wrapInTransaction')->willReturnCallback(static function (callable $operation) use (&$attempts, $deadlock): mixed {
            ++$attempts;
            $result = $operation();
            if (1 === $attempts) {
                throw $deadlock;
            }

            return $result;
        });
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('resetManager');
        $games = $this->createMock(GameRepositoryInterface::class);
        $games->expects(self::exactly(2))->method('findOneByGameIdWithSeatsForUpdate')->with(7)->willReturnOnConsecutiveCalls($detached, $reloaded);
        $seen = [];
        $transaction = new LockedGameTransaction($this->createStub(GameAccessServiceInterface::class), $entityManager, new RetryingTransaction($entityManager, $registry), $games);

        $result = $transaction->runWithRetry(7, static function (Game $game) use (&$seen): Game {
            $seen[] = $game;

            return $game;
        });

        self::assertSame([$detached, $reloaded], $seen);
        self::assertSame($reloaded, $result);
    }
}
