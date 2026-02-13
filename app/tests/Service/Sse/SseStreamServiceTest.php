<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Sse;

function connection_aborted(): bool
{
    static $calls = 0;

    return $calls++ > 0; // false on first call, true on next -> single loop iteration
}

function ob_flush(): bool
{
    return true; // no-op for tests
}

function flush(): void
{
    // no-op for tests
}

namespace App\Tests\Service\Sse;

use App\Dto\ScoreboardDeltaDto;
use App\Dto\ThrowAckDto;
use App\Service\Game\GameRoomServiceInterface;
use App\Service\Game\GameDeltaServiceInterface;
use App\Service\Sse\SseStreamService;
use App\Repository\RoundThrowsRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SseStreamServiceTest extends TestCase
{
    private GameRoomServiceInterface&MockObject $gameRoomService;
    private RoundThrowsRepositoryInterface&MockObject $roundThrowsRepository;
    private GameDeltaServiceInterface&MockObject $gameDeltaService;
    private SseStreamService $service;

    protected function setUp(): void
    {
        $this->gameRoomService = $this->createMock(GameRoomServiceInterface::class);
        $this->roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $this->gameDeltaService = $this->createMock(GameDeltaServiceInterface::class);
        $this->service = new SseStreamService($this->gameRoomService, $this->roundThrowsRepository, $this->gameDeltaService);
    }

    public function testCreatePlayerStreamProducesEventsAndHeaders(): void
    {
        $this->gameRoomService
            ->expects(self::once())
            ->method('getPlayersWithUserInfo')
            ->with(42)
            ->willReturn([
                ['id' => 1, 'name' => 'u1', 'position' => 2],
                ['id' => 2, 'name' => 'u2', 'position' => 1],
            ]);

        $this->roundThrowsRepository
            ->expects(self::once())
            ->method('findLatestForGame')
            ->with(42)
            ->willReturn([
                'id' => 99,
                'throwNumber' => 1,
                'value' => 20,
                'isDouble' => false,
                'isTriple' => false,
                'isBust' => false,
                'score' => 20,
                'timestamp' => new \DateTimeImmutable('2024-01-01T10:00:00Z'),
                'roundNumber' => 1,
                'playerId' => 1,
                'playerName' => 'u1',
            ]);

        $game = new \App\Entity\Game();
        $game->setGameId(42);
        $this->gameRoomService
            ->expects(self::once())
            ->method('findGameById')
            ->with(42)
            ->willReturn($game);

        $this->gameDeltaService
            ->expects(self::once())
            ->method('buildThrowAck')
            ->with($game, self::isType('array'))
            ->willReturn(new ThrowAckDto(
                success: true,
                gameId: 42,
                stateVersion: 'v1',
                throw: null,
                scoreboardDelta: new ScoreboardDeltaDto(
                    changedPlayers: [],
                    winnerId: null,
                    status: 'started',
                    currentRound: 1,
                ),
                serverTs: '2026-02-13T00:00:00+00:00',
            ));

        $response = $this->service->createPlayerStream(42);

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('text/event-stream', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        self::assertSame('keep-alive', $response->headers->get('Connection'));
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));

        $callback = $response->getCallback();
        self::assertNotNull($callback);

        ob_start();
        ($callback)();
        $output = ob_get_clean();

        self::assertStringContainsString('event: players', $output);
        self::assertStringContainsString('"count":2', $output);
        self::assertStringContainsString('"position":1', $output);
        self::assertStringContainsString('event: throw', $output);
        self::assertStringContainsString('"stateVersion":"v1"', $output);
    }
}
