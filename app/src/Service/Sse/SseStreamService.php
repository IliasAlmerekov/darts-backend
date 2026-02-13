<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Sse;

use App\Service\Game\GameDeltaServiceInterface;
use App\Service\Game\GameRoomServiceInterface;
use DateTimeInterface;
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
    /**
     * @param GameRoomServiceInterface       $gameRoomService
     * @param RoundThrowsRepositoryInterface $roundThrowsRepository
     * @param GameDeltaServiceInterface      $gameDeltaService
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        private GameRoomServiceInterface $gameRoomService,
        private RoundThrowsRepositoryInterface $roundThrowsRepository,
        private GameDeltaServiceInterface $gameDeltaService,
    )
    {
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
                    echo 'id: '.$eventId."\n";
                    echo "event: players\n";
                    echo 'data: '.$payload."\n\n";
                    @ob_flush();
                    @flush();
                }

                $latestThrow = $this->roundThrowsRepository->findLatestForGame($gameId);
                if (is_array($latestThrow) && isset($latestThrow['id']) && $latestThrow['id'] !== $lastThrowId) {
                    $lastThrowId = $latestThrow['id'];
                    $eventId++;
                    $game = $this->gameRoomService->findGameById($gameId);
                    $deltaPayload = $latestThrow;
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
