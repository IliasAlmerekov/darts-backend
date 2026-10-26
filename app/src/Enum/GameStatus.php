<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * This enum represents the status of a game.
 */
enum GameStatus: string
{
    case Lobby = 'lobby';
    case Started = 'started';
    case Finished = 'finished';
    case Aborted = 'aborted';

    /**
     * Whether finishing is allowed. A finished game stays finished, so a repeated finish is a no-op.
     *
     * @return bool
     */
    public function canFinish(): bool
    {
        return self::Started === $this || self::Finished === $this;
    }

    /**
     * Whether aborting is allowed. An aborted game stays aborted, so a repeated abort is a no-op.
     *
     * @return bool
     */
    public function canAbort(): bool
    {
        return self::Started === $this || self::Aborted === $this;
    }

    /**
     * Whether the last throw can be undone. Undo in a finished game makes it started again.
     *
     * @return bool
     */
    public function canUndo(): bool
    {
        return self::Started === $this || self::Finished === $this;
    }
}
