<?php

declare(strict_types=1);

namespace App\Service\Sse;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Interface for SSE (Server-Sent Events) stream service operations.
 */
interface SseStreamServiceInterface
{
    /**
     * Create a SSE stream for the players of a room in the lobby.
     * Sends `players` events while the game is in the lobby, and ends when it leaves the lobby
     * or after a lifetime cap.
     *
     * @param int $gameId The game ID to stream updates for
     *
     * @return StreamedResponse A streaming response with SSE events
     */
    public function createPlayerStream(int $gameId): StreamedResponse;
}
