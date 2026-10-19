<?php

declare(strict_types=1);

namespace App\Tests\Service\Persistence;

use App\Service\Persistence\RetryingTransaction;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PDOException;
use PHPUnit\Framework\TestCase;

final class RetryingTransactionTest extends TestCase
{
    public function testRunsTheOperationAgainInAFreshEntityManagerAfterADeadlock(): void
    {
        $deadlock = $this->deadlock();
        $calls = 0;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))
            ->method('wrapInTransaction')
            ->willReturnCallback(static function (callable $operation): mixed {
                return $operation();
            });
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('resetManager');

        $result = (new RetryingTransaction($entityManager, $registry))->run(static function () use (&$calls, $deadlock): string {
            ++$calls;
            if (1 === $calls) {
                throw $deadlock;
            }

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(2, $calls);
    }

    public function testRethrowsWhenTheSecondAttemptDeadlocksToo(): void
    {
        $deadlock = $this->deadlock();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))
            ->method('wrapInTransaction')
            ->willThrowException($deadlock);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('resetManager');

        $this->expectExceptionObject($deadlock);
        (new RetryingTransaction($entityManager, $registry))->run(static fn (): string => 'never');
    }

    public function testDoesNotRetryOtherFailures(): void
    {
        $failure = new \RuntimeException('not a deadlock');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('wrapInTransaction')
            ->willThrowException($failure);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('resetManager');

        $this->expectExceptionObject($failure);
        (new RetryingTransaction($entityManager, $registry))->run(static fn (): string => 'never');
    }

    private function deadlock(): DeadlockException
    {
        return new DeadlockException(PdoDriverException::new(new PDOException('Deadlock found when trying to get lock')), null);
    }
}
