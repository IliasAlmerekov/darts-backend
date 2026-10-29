<?php

/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

final readonly class ThrowScore
{
    public function __construct(public int $value, public int $score, public bool $bust)
    {
    }
}
