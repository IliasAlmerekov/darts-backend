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

use App\Enum\GameStatus;
use App\Repository\GameRepositoryInterface;
use App\Service\Game\GameRoomServiceInterface;
use App\Service\Sse\SseStreamService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The room stream serves only the lobby (ADR 0001, #30).
 */
final class SseStreamServiceTest extends TestCase
{
    private GameRoomServiceInterface&Stub $gameRoomService;
    private GameRepositoryInterface&Stub $gameRepository;
    private SseStreamService $service;

    protected function setUp(): void
    {
        $GLOBALS['__sse_connection_aborted_calls'] = 0;
        $GLOBALS['__sse_connection_aborted_false_loops'] = PHP_INT_MAX;
        $GLOBALS['__sse_microtime_tick'] = 0;
        $GLOBALS['__sse_microtime_start'] = 1000.0;
        $GLOBALS['__sse_microtime_step'] = 0.5;
        $GLOBALS['__sse_usleep_calls'] = [];

        $this->gameRoomService = $this->createStub(GameRoomServiceInterface::class);
        $this->gameRepository = $this->createStub(GameRepositoryInterface::class);
        $this->service = new SseStreamService($this->gameRoomService, $this->gameRepository);
    }

    public function testLobbyStreamSendsPlayersWhenAParticipantJoinsOrLeaves(): void
    {
        $GLOBALS['__sse_connection_aborted_false_loops'] = 8;
        $this->gameRepository->method('findStatus')->willReturn(GameStatus::Lobby);
        $one = [['id' => 1, 'name' => 'u1', 'position' => 1]];
        $two = [...$one, ['id' => 2, 'name' => 'u2', 'position' => 2]];
        $this->gameRoomService->method('getPlayersWithUserInfo')
            ->willReturnOnConsecutiveCalls($one, $one, $two, $one);

        $response = $this->service->createPlayerStream(42);
        $output = $this->runStream($response->getCallback());

        self::assertSame('text/event-stream', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        self::assertSame('keep-alive', $response->headers->get('Connection'));
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));
        self::assertSame(
            [
                ['players' => $one, 'count' => 1],
                ['players' => $two, 'count' => 2],
                ['players' => $one, 'count' => 1],
            ],
            $this->playersEvents($output),
        );
        self::assertStringNotContainsString('event: throw', $output);
    }

    /**
     * @return iterable<string, array{GameStatus|null}>
     */
    public static function statusAfterTheLobby(): iterable
    {
        yield 'started' => [GameStatus::Started];
        yield 'aborted' => [GameStatus::Aborted];
        yield 'deleted' => [null];
    }

    #[DataProvider('statusAfterTheLobby')]
    public function testStreamEndsWithinOnePollIntervalAfterTheGameLeavesTheLobby(?GameStatus $status): void
    {
        $this->gameRepository->method('findStatus')
            ->willReturnOnConsecutiveCalls(GameStatus::Lobby, GameStatus::Lobby, $status);
        $this->gameRoomService->method('getPlayersWithUserInfo')->willReturn([]);

        $output = $this->runStream($this->service->createPlayerStream(7)->getCallback());

        // The clock starts at 1000.0 and moves 0.5 s per read. Status polls run at 1000.5,
        // 1001.5 and 1002.5; the stream stops at the third one, one poll after the start.
        self::assertEqualsWithDelta(1002.5, $this->lastClockReading(), 0.001);
        self::assertCount(1, $this->playersEvents($output));
        self::assertStringNotContainsString('event: throw', $output);
    }

    public function testStreamEndsAfterTheLifetimeCapWhileTheGameStaysInTheLobby(): void
    {
        $this->gameRepository->method('findStatus')->willReturn(GameStatus::Lobby);
        $this->gameRoomService->method('getPlayersWithUserInfo')->willReturn([]);
        $service = new SseStreamService($this->gameRoomService, $this->gameRepository, maxLifetimeSeconds: 2.0);

        // The client never disconnects, so only the cap ends the loop. The clock starts at
        // 1000.0 and moves 0.5 s per read: idle loops at 1000.5, 1001.0 and 1001.5, then
        // the cap closes the stream at 1002.0.
        $this->runStream($service->createPlayerStream(7)->getCallback());

        self::assertEqualsWithDelta(1002.0, $this->lastClockReading(), 0.001);
        self::assertCount(3, $GLOBALS['__sse_usleep_calls']);
    }

    public function testStreamOnAGameOutsideTheLobbySendsNoEvent(): void
    {
        $this->gameRepository->method('findStatus')->willReturn(GameStatus::Finished);
        $this->gameRoomService->method('getPlayersWithUserInfo')->willReturn([['id' => 1, 'name' => 'u1', 'position' => 1]]);

        $output = $this->runStream($this->service->createPlayerStream(7)->getCallback());

        self::assertStringNotContainsString('event:', $output);
    }

    private function runStream(?callable $callback): string
    {
        self::assertNotNull($callback);
        ob_start();
        $callback();

        return (string) ob_get_clean();
    }

    /**
     * @return list<mixed>
     */
    private function playersEvents(string $output): array
    {
        preg_match_all('/^event: players\ndata: (.+)$/m', $output, $matches);

        return array_map(static fn (string $json): mixed => json_decode($json, true), $matches[1]);
    }

    private function lastClockReading(): float
    {
        $tick = (int) $GLOBALS['__sse_microtime_tick'];

        return (float) $GLOBALS['__sse_microtime_start'] + ($tick - 1) * (float) $GLOBALS['__sse_microtime_step'];
    }
}
