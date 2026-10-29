<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Game;
use App\Exception\Game\GameNotFoundException;
use App\Service\Game\LockedGameTransactionInterface;
use Closure;

/**
 * Hands the use case the given game as if it were locked, for unit tests of the use case.
 * LockedGameTransactionTest covers the real admin check, transaction and lock.
 */
final readonly class LockedGameTransactionFake implements LockedGameTransactionInterface
{
    /**
     * @param Game|null $game the game every id resolves to; null means no game exists
     */
    public function __construct(private ?Game $game)
    {
    }

    public function run(int $gameId, Closure $operation): mixed
    {
        return $operation($this->game ?? throw new GameNotFoundException());
    }

    public function runWithRetry(int $gameId, Closure $operation): mixed
    {
        return $operation($this->game ?? throw new GameNotFoundException());
    }
}
