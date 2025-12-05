<?php

declare(strict_types=1);

namespace App\Service {
    // Override globals used inside the service loop to make it test-friendly.
    if (!function_exists(__NAMESPACE__.'\connection_aborted')) {
        function connection_aborted(): bool
        {
            static $call = 0;

            // First iteration runs, second stops the loop.
            return ++$call >= 2;
        }
    }

    if (!function_exists(__NAMESPACE__.'\sleep')) {
        function sleep(int $seconds): void
        {
            // no-op for tests
        }
    }

    if (!function_exists(__NAMESPACE__.'\ob_flush')) {
        function ob_flush(): bool
        {
            return true;
        }
    }

    if (!function_exists(__NAMESPACE__.'\flush')) {
        function flush(): void
        {
            // no-op
        }
    }
}

namespace App\Tests\Service {

    use App\Entity\Game;
    use App\Repository\GamePlayersRepositoryInterface;
    use App\Repository\GameRepositoryInterface;
    use App\Repository\RoundThrowsRepositoryInterface;
    use App\Service\GameRoomService;
    use App\Service\PlayerManagementService;
    use App\Service\SseStreamService;
    use DateTimeImmutable;
    use Doctrine\ORM\EntityManagerInterface;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\HttpFoundation\StreamedResponse;

    final class SseStreamServiceTest extends TestCase
    {
        public function testCreatePlayerStreamEmitsPlayersAndThrowEvent(): void
        {
            $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
            $gamePlayersRepository->method('findPlayersWithUserInfo')
                ->with(7)
                ->willReturn([
                    ['id' => 1, 'username' => 'Alice'],
                    ['id' => 2, 'username' => 'Bob'],
                ]);

            $gameRepository = $this->createMock(GameRepositoryInterface::class);
            $entityManager = $this->createMock(EntityManagerInterface::class);

            $playerManagementService = new PlayerManagementService($gamePlayersRepository, $entityManager);
            $gameRoomService = new GameRoomService(
                $gameRepository,
                $gamePlayersRepository,
                $entityManager,
                $playerManagementService
            );

            $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
            $roundThrowsRepository->method('findLatestForGame')
                ->with(7)
                ->willReturn([
                    'id' => 11,
                    'playerId' => 1,
                    'value' => 20,
                    'timestamp' => new DateTimeImmutable('2024-01-01T00:00:00Z'),
                ]);

            $service = new SseStreamService($gameRoomService, $roundThrowsRepository);

            $response = $service->createPlayerStream(7);
            self::assertInstanceOf(StreamedResponse::class, $response);
            self::assertSame('text/event-stream', $response->headers->get('Content-Type'));

            $callback = $response->getCallback();
            self::assertIsCallable($callback);

            ob_start();
            $callback();
            $output = (string) ob_get_clean();

            self::assertStringContainsString('event: players', $output);
            self::assertStringContainsString('"username":"Alice"', $output);
            self::assertStringContainsString('event: throw', $output);
            self::assertStringContainsString('"id":11', $output);
            self::assertStringContainsString(': heartbeat', $output);
        }
    }
}
