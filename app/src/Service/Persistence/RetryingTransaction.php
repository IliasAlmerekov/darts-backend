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
 * Closes the deadlock left in ADR 0003: two requests that lost the lobby insert race both hold a
 * shared lock on the lobby row and both ask for an exclusive one. MySQL rolls one back, and the
 * second attempt finds the lobby the other request committed.
 *
 * @psalm-suppress UnusedClass Reason: service is auto-wired and used via interface.
 */
final readonly class RetryingTransaction implements RetryingTransactionInterface
{
    /**
     * @param EntityManagerInterface $entityManager
     * @param ManagerRegistry        $registry
     *
     * @psalm-suppress PossiblyUnusedMethod
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
