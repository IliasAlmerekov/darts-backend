<?php

declare(strict_types=1);

namespace App\Tests\Service {

    use App\Repository\GamePlayersRepositoryInterface;
    use App\Repository\GameRepositoryInterface;
    use App\Repository\RoundThrowsRepositoryInterface;
    use App\Service\Game\GameRoomService;
    use App\Service\Player\PlayerManagementService;
    use App\Service\Sse\SseStreamService;
    use Doctrine\ORM\EntityManagerInterface;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\HttpFoundation\StreamedResponse;

    final class SseStreamServiceTest extends TestCase
    {
        /**
         * Test that createPlayerStream returns a proper StreamedResponse with SSE headers.
         * Note: We cannot test the actual streaming behavior because it contains an infinite loop
         * that relies on connection_aborted() which cannot be reliably mocked with @ suppression.
         */
        public function testCreatePlayerStreamReturnsStreamedResponseWithCorrectHeaders(): void
        {
            $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
            $gameRepository = $this->createMock(GameRepositoryInterface::class);
            $entityManager = $this->createMock(EntityManagerInterface::class);
            $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);

            $playerManagementService = new PlayerManagementService($gamePlayersRepository, $entityManager);
            $gameRoomService = new GameRoomService(
                $gameRepository,
                $gamePlayersRepository,
                $entityManager,
                $playerManagementService
            );

            $service = new SseStreamService($gameRoomService, $roundThrowsRepository);

            $response = $service->createPlayerStream(7);

            self::assertInstanceOf(StreamedResponse::class, $response);
            self::assertSame('text/event-stream', $response->headers->get('Content-Type'));
            self::assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
            self::assertSame('keep-alive', $response->headers->get('Connection'));
            self::assertSame('no', $response->headers->get('X-Accel-Buffering'));

            $callback = $response->getCallback();
            self::assertIsCallable($callback);

            // Note: Cannot safely execute callback as it contains an infinite loop
        }
    }
}
