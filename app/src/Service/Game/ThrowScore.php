<?php

/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

/** Result of scoring one dart. */
final readonly class ThrowScore
{
    /**
     * @param int  $value
     * @param int  $score
     * @param bool $bust
     */
    public function __construct(public int $value, public int $score, public bool $bust)
    {
    }
}
