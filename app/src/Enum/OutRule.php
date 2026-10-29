<?php

/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Enum;

/** Supported checkout rules. */
enum OutRule: string
{
    case Single = 'singleout';
    case Double = 'doubleout';
    case Triple = 'tripleout';
}
