<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

/**
 * Lightweight acknowledgement payload returned after a throw write.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class ThrowAckDto
{
    /**
     * @param bool               $success
     * @param int                $gameId
     * @param string             $stateVersion
     * @param ThrowDeltaDto|null $throw
     * @param ScoreboardDeltaDto $scoreboardDelta
     * @param string             $serverTs
     */
    public function __construct(
        public bool $success,
        public int $gameId,
        public string $stateVersion,
        public ?ThrowDeltaDto $throw,
        public ScoreboardDeltaDto $scoreboardDelta,
        public string $serverTs,
    ) {
    }
}
