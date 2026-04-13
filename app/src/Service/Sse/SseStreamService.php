<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Sse;

use App\Entity\Game;
use App\Service\Game\GameDeltaServiceInterface;
use App\Service\Game\GameRoomServiceInterface;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Repository\RoundThrowsRepositoryInterface;
use Override;

/**
 * Service to create SSE streams for players and throws.
 * This class is responsible for sending updates to the client via SSE.
 *
 * @psalm-suppress UnusedClass Reason: service is auto-wired and used via interface.
 */
final readonly class SseStreamService implements SseStreamServiceInterface
{
    private const THROW_POLL_INTERVAL_SECONDS = 0.1;
    private const PLAYERS_POLL_INTERVAL_SECONDS = 1.0;
    private const HEARTBEAT_INTERVAL_SECONDS = 15.0;
    private const LOOP_IDLE_MICROSECONDS = 50_000;

    /**
     * @param GameRoomServiceInterface       $gameRoomService
     * @param RoundThrowsRepositoryInterface $roundThrowsRepository
     * @param GameDeltaServiceInterface      $gameDeltaService
     * @param EntityManagerInterface         $entityManager
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        private GameRoomServiceInterface $gameRoomService,
        private RoundThrowsRepositoryInterface $roundThrowsRepository,
        private GameDeltaServiceInterface $gameDeltaService,
        private EntityManagerInterface $entityManager,
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
            $lastThrowId = null;
            $nextPlayersPollAt = 0.0;
            $nextThrowPollAt = 0.0;
            $nextHeartbeatAt = 0.0;
            echo ": init\n\n";
            @ob_flush();
            @flush();
            while (!connection_aborted()) {
                $now = microtime(true);

                if ($now >= $nextPlayersPollAt) {
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

                if ($now >= $nextThrowPollAt) {
                    $latestThrow = $this->roundThrowsRepository->findLatestForGame($gameId);
                    if (is_array($latestThrow) && isset($latestThrow['id']) && $latestThrow['id'] !== $lastThrowId) {
                        $lastThrowId = $latestThrow['id'];
                        $eventId++;
                        $deltaPayload = $latestThrow;
                        $game = $this->loadFreshGameForDelta($gameId);
                        if (null !== $game) {
                            $ack = $this->gameDeltaService->buildThrowAck($game, $latestThrow);
                            $deltaPayload = [
                                'gameId' => $ack->gameId,
                                'stateVersion' => $ack->stateVersion,
                                'throw' => $ack->throw,
                                'scoreboardDelta' => $ack->scoreboardDelta,
                                'serverTs' => $ack->serverTs,
                            ];
                        } elseif ($latestThrow['timestamp'] instanceof DateTimeInterface) {
                            $latestThrow['timestamp'] = $latestThrow['timestamp']->format(DateTimeInterface::ATOM);
                            $deltaPayload = $latestThrow;
                        }

                        echo 'id: '.$eventId."\n";
                        echo "event: throw\n";
                        $jsonEncoded = json_encode($deltaPayload);
                        if (false !== $jsonEncoded) {
                            echo 'data: '.$jsonEncoded."\n\n";
                        }
                        @ob_flush();
                        @flush();
                    }
                    $nextThrowPollAt = $now + self::THROW_POLL_INTERVAL_SECONDS;
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

    /**
     * @param int $gameId
     *
     * @return Game|null
     */
    private function loadFreshGameForDelta(int $gameId): ?Game
    {
        // Long-lived SSE requests keep Doctrine's identity map alive.
        // Clearing guarantees fresh game/player scores for each throw delta.
        $this->entityManager->clear();

        return $this->gameRoomService->findGameById($gameId);
    }
}
