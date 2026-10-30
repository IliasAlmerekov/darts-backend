<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Persistence;

use Closure;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Override;

/**
 * Runs an operation in a transaction, and once more in a new transaction when MySQL rolls the
 * first one back for a deadlock or a lock wait timeout. Room create and rematch use it for the
 * deadlock described in ADR 0003.
 *
 * @psalm-suppress UnusedClass Reason: service is auto-wired and used via interface.
 */
final readonly class RetryingTransaction implements RetryingTransactionInterface
{
    /**
     * @param EntityManagerInterface $entityManager
     * @param ManagerRegistry        $registry
     *
     */
    public function __construct(private EntityManagerInterface $entityManager, private ManagerRegistry $registry)
    {
    }

    /**
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    #[Override]
    public function run(Closure $operation): mixed
    {
        try {
            return $this->entityManager->wrapInTransaction($operation);
        } catch (RetryableException) {
            // A failed wrapInTransaction() closes the entity manager. Resetting it reopens the
            // injected instance in place.
            $this->registry->resetManager();

            return $this->entityManager->wrapInTransaction($operation);
        }
    }
}
