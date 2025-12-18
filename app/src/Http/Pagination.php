<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Http;

/**
 * Normalizes and stores pagination parameters.
 *
 * @psalm-immutable
 */
final class Pagination
{
    /**
     * @param int $limit
     * @param int $offset
     */
    public function __construct(
        public int $limit,
        public int $offset,
    ) {
    }

    /**
     * @param int $limit
     * @param int $offset
     * @param int $defaultLimit Used when $limit <= 0
     * @param int $maxLimit
     *
     * @return self
     */
    public static function from(int $limit, int $offset, int $defaultLimit = 20, int $maxLimit = 100): self
    {
        $limitValue = $limit > 0 ? $limit : $defaultLimit;
        $limitValue = max(1, min($maxLimit, $limitValue));
        $offsetValue = max(0, $offset);

        return new self($limitValue, $offsetValue);
    }
}
