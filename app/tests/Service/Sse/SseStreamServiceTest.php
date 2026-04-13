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
    $calls = (int) ($GLOBALS['__sse_connection_aborted_calls'] ?? 0);
    $calls++;
    $GLOBALS['__sse_connection_aborted_calls'] = $calls;
    $falseLoops = (int) ($GLOBALS['__sse_connection_aborted_false_loops'] ?? 1);

    return $calls > $falseLoops;
}

function ob_flush(): bool
{
    return true; // no-op for tests
}

function flush(): void
{
    // no-op for tests
}

function microtime(bool $asFloat = false): float|string
{
    $tick = (int) ($GLOBALS['__sse_microtime_tick'] ?? 0);
    $start = (float) ($GLOBALS['__sse_microtime_start'] ?? 1000.0);
    $step = (float) ($GLOBALS['__sse_microtime_step'] ?? 0.05);
    $value = $start + ($tick * $step);
    $GLOBALS['__sse_microtime_tick'] = $tick + 1;

    return $asFloat ? $value : (string) $value;
}

function usleep(int $microseconds): int
{
    if (!isset($GLOBALS['__sse_usleep_calls']) || !is_array($GLOBALS['__sse_usleep_calls'])) {
        $GLOBALS['__sse_usleep_calls'] = [];
    }

    $GLOBALS['__sse_usleep_calls'][] = $microseconds;

    return 0;
}

namespace App\Tests\Service\Sse;

use App\Dto\ScoreboardDeltaDto;
use App\Dto\ThrowAckDto;
use App\Service\Game\GameRoomServiceInterface;
use App\Service\Game\GameDeltaServiceInterface;
use App\Service\Sse\SseStreamService;
use App\Repository\RoundThrowsRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SseStreamServiceTest extends TestCase
{
    private GameRoomServiceInterface&MockObject $gameRoomService;
    private RoundThrowsRepositoryInterface&MockObject $roundThrowsRepository;
    private GameDeltaServiceInterface&MockObject $gameDeltaService;
    private EntityManagerInterface&MockObject $entityManager;
    private SseStreamService $service;

    protected function setUp(): void
    {
        $GLOBALS['__sse_connection_aborted_calls'] = 0;
        $GLOBALS['__sse_connection_aborted_false_loops'] = 1;
        $GLOBALS['__sse_microtime_tick'] = 0;
        $GLOBALS['__sse_microtime_start'] = 1000.0;
        $GLOBALS['__sse_microtime_step'] = 0.05;
        $GLOBALS['__sse_usleep_calls'] = [];

        $this->gameRoomService = $this->createMock(GameRoomServiceInterface::class);
        $this->roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $this->gameDeltaService = $this->createMock(GameDeltaServiceInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->service = new SseStreamService(
            $this->gameRoomService,
            $this->roundThrowsRepository,
            $this->gameDeltaService,
            $this->entityManager,
        );
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
        $this->entityManager
            ->expects(self::once())
            ->method('clear');

        $this->gameDeltaService
            ->expects(self::once())
            ->method('buildThrowAck')
            ->with($game, self::isArray())
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

    public function testCreatePlayerStreamPollsThrowsFrequentlyAndPlayersOnceWithinOneSecondWindow(): void
    {
        $GLOBALS['__sse_connection_aborted_false_loops'] = 3;

        $this->gameRoomService
            ->expects(self::once())
            ->method('getPlayersWithUserInfo')
            ->with(99)
            ->willReturn([
                ['id' => 1, 'name' => 'u1', 'position' => 1],
            ]);

        $this->roundThrowsRepository
            ->expects(self::exactly(2))
            ->method('findLatestForGame')
            ->with(99)
            ->willReturnOnConsecutiveCalls(
                [
                    'id' => 501,
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
                ],
                [
                    'id' => 501,
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
                ],
            );

        $game = new \App\Entity\Game();
        $game->setGameId(99);
        $this->gameRoomService
            ->expects(self::once())
            ->method('findGameById')
            ->with(99)
            ->willReturn($game);
        $this->entityManager
            ->expects(self::once())
            ->method('clear');

        $this->gameDeltaService
            ->expects(self::once())
            ->method('buildThrowAck')
            ->with($game, self::isArray())
            ->willReturn(new ThrowAckDto(
                success: true,
                gameId: 99,
                stateVersion: 'v-fast',
                throw: null,
                scoreboardDelta: new ScoreboardDeltaDto(
                    changedPlayers: [],
                    winnerId: null,
                    status: 'started',
                    currentRound: 1,
                ),
                serverTs: '2026-03-19T00:00:00+00:00',
            ));

        $response = $this->service->createPlayerStream(99);
        $callback = $response->getCallback();
        self::assertNotNull($callback);

        ob_start();
        ($callback)();
        $output = ob_get_clean();

        self::assertStringContainsString('event: players', $output);
        self::assertStringContainsString('event: throw', $output);
        self::assertSame([50_000, 50_000, 50_000], $GLOBALS['__sse_usleep_calls']);
    }
}
