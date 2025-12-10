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
     * Create a SSE stream for player updates and throws.
     * Streams real-time updates about players and their throws to the client.
     *
     * @param int $gameId The game ID to stream updates for
     *
     * @return StreamedResponse A streaming response with SSE events
     */
    public function createPlayerStream(int $gameId): StreamedResponse;
}
