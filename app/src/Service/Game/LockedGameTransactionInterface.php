<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use App\Exception\Game\GameNotFoundException;
use Closure;

/**
 * The preamble of every write use case on an existing game: admin check, transaction,
 * locked load of the game and its seats by id, then the use case.
 */
interface LockedGameTransactionInterface
{
    /**
     * Runs the operation in a plain transaction, without retry. A deadlock reaches the client
     * (ADR 0004).
     *
     * @template T
     *
     * @param int             $gameId
     * @param Closure(Game):T $operation
     *
     * @return T
     *
     * @throws GameNotFoundException
     */
    public function run(int $gameId, Closure $operation): mixed;

    /**
     * Runs the operation in a RetryingTransactionInterface transaction. Each attempt loads the
     * game by id again, because a retry detaches what the attempt before it loaded.
     *
     * @template T
     *
     * @param int             $gameId
     * @param Closure(Game):T $operation
     *
     * @return T
     *
     * @throws GameNotFoundException
     */
    public function runWithRetry(int $gameId, Closure $operation): mixed;
}
