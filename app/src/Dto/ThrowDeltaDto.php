<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

/**
 * Compact payload for a single recorded throw.
 */
final class ThrowDeltaDto
{
    /**
     * @param int    $id
     * @param int    $playerId
     * @param string $playerName
     * @param int    $value
     * @param bool   $isDouble
     * @param bool   $isTriple
     * @param bool   $isBust
     * @param int    $score
     * @param int    $roundNumber
     * @param string $timestamp
     */
    public function __construct(
        public int $id,
        public int $playerId,
        public string $playerName,
        public int $value,
        public bool $isDouble,
        public bool $isTriple,
        public bool $isBust,
        public int $score,
        public int $roundNumber,
        public string $timestamp,
    ) {
    }
}
