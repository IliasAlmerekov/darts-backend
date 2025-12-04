<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * This class is used to serialize throw response
 */
final class ThrowResponseDto
{
    /**
     * @param int  $value
     * @param bool $isDouble
     * @param bool $isTriple
     * @param bool $isBust
     */
    public function __construct(
        public int $value,
        public bool $isDouble,
        public bool $isTriple,
        public bool $isBust,
    ) {
    }
}
