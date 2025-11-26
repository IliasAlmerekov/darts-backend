<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\StreamedResponse;

class SseStreamService
{
    public function __construct(
        private GameRoomService $gameRoomService
    )
    {
    }

    public function createPlayerStream(int $gameId): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($gameId) {
            set_time_limit(0);
            $eventId = 0;
            $lastPayload = null;

            echo ": init\n\n";
            @ob_flush();
            @flush();

            while (!connection_aborted()) {
                $players = $this->gameRoomService->getPlayersWithUserInfo($gameId);
                $payload = json_encode([
                    'players' => $players,
                    'count' => count($players),
                ]);

                if (false !== $payload && $payload !== $lastPayload) {
                    $lastPayload = $payload;
                    $eventId++;

                    echo 'id: ' . $eventId . "\n";
                    echo "event: players\n";
                    echo 'data: ' . $payload . "\n\n";
                    @ob_flush();
                    @flush();
                }

                echo ": heartbeat\n\n";
                @ob_flush();
                @flush();
                sleep(1);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}
