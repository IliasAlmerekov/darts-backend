<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

/**
 * Lightweight acknowledgement payload returned after undoing a throw.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class UndoAckDto
{
    /**
     * @param bool               $success
     * @param int                $gameId
     * @param string             $stateVersion
     * @param ThrowDeltaDto|null $undoneThrow
     * @param ScoreboardDeltaDto $scoreboardDelta
     * @param string             $serverTs
     */
    public function __construct(
        public bool $success,
        public int $gameId,
        public string $stateVersion,
        public ?ThrowDeltaDto $undoneThrow,
        public ScoreboardDeltaDto $scoreboardDelta,
        public string $serverTs,
    ) {
    }
}
