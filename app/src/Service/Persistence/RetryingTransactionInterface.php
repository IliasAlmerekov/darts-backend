<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Persistence;

use Closure;

/**
 * Runs an operation in a transaction and runs it once more when the database reports a deadlock.
 */
interface RetryingTransactionInterface
{
    /**
     * Runs the operation in a transaction. After a deadlock or a lock wait timeout it resets the
     * entity manager and runs the operation once more, in a new transaction. Entities loaded
     * before the call are detached for the second attempt, so the operation must only read
     * their fields.
     *
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    public function run(Closure $operation): mixed;
}
