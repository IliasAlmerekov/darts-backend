<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Sse;

use App\Enum\GameStatus;
use App\Repository\GameRepositoryInterface;
use App\Service\Game\GameRoomServiceInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Override;

/**
 * Service to create the SSE stream for a room in the lobby (ADR 0001).
 * The stream sends the players of the room and ends when the game leaves the lobby.
 *
 * @psalm-suppress UnusedClass Reason: service is auto-wired and used via interface.
 */
final readonly class SseStreamService implements SseStreamServiceInterface
{
    private const PLAYERS_POLL_INTERVAL_SECONDS = 1.0;
    private const HEARTBEAT_INTERVAL_SECONDS = 15.0;
    private const LOOP_IDLE_MICROSECONDS = 50_000;

    /**
     * @param GameRoomServiceInterface $gameRoomService
     * @param GameRepositoryInterface  $gameRepository
     * @param float                    $maxLifetimeSeconds Each open stream holds a php-fpm worker and a DB
     *                                                     connection; EventSource reconnects after the cap.
     *
     */
    public function __construct(
        private GameRoomServiceInterface $gameRoomService,
        private GameRepositoryInterface $gameRepository,
        private float $maxLifetimeSeconds = 300.0,
    ) {
    }

    /**
     * @param int $gameId
     *
     * @return StreamedResponse
     */
    #[Override]
    public function createPlayerStream(int $gameId): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($gameId) {
            set_time_limit(0);
            $eventId = 0;
            $lastPayload = null;
            $nextPlayersPollAt = 0.0;
            $nextHeartbeatAt = 0.0;
            $closeAt = microtime(true) + $this->maxLifetimeSeconds;
            echo ": init\n\n";
            @ob_flush();
            @flush();
            while (!connection_aborted()) {
                $now = microtime(true);
                if ($now >= $closeAt) {
                    break;
                }

                if ($now >= $nextPlayersPollAt) {
                    if (GameStatus::Lobby !== $this->gameRepository->findStatus($gameId)) {
                        break;
                    }

                    $players = $this->gameRoomService->getPlayersWithUserInfo($gameId);
                    $payload = json_encode([
                        'players' => $players,
                        'count' => count($players),
                    ]);
                    if (false !== $payload && $payload !== $lastPayload) {
                        $lastPayload = $payload;
                        $eventId++;
                        echo 'id: '.$eventId."\n";
                        echo "event: players\n";
                        echo 'data: '.$payload."\n\n";
                        @ob_flush();
                        @flush();
                    }
                    $nextPlayersPollAt = $now + self::PLAYERS_POLL_INTERVAL_SECONDS;
                }

                if ($now >= $nextHeartbeatAt) {
                    echo ": heartbeat\n\n";
                    @ob_flush();
                    @flush();
                    $nextHeartbeatAt = $now + self::HEARTBEAT_INTERVAL_SECONDS;
                }

                usleep(self::LOOP_IDLE_MICROSECONDS);
            }
        });
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}
