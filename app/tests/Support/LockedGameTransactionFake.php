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
final class LockedGameTransactionFake implements LockedGameTransactionInterface
{
    /** @var list<string> the methods called, in order */
    public array $calls = [];

    /**
     * @param Game|null $game the game every id resolves to; null means no game exists
     */
    public function __construct(private readonly ?Game $game)
    {
    }

    public function run(int $gameId, Closure $operation): mixed
    {
        $this->calls[] = 'run';

        return $operation($this->game ?? throw new GameNotFoundException());
    }

    public function runWithRetry(int $gameId, Closure $operation): mixed
    {
        $this->calls[] = 'runWithRetry';

        return $operation($this->game ?? throw new GameNotFoundException());
    }
}
