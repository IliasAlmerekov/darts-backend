<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Controller\GameLifecycleController;
use App\Controller\GameStatsController;
use App\Dto\GameResponseDto;
use App\Dto\PlayerStatsResponseDto;
use App\EventSubscriber\ApiViewSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Serializer\SerializerInterface;

final class ApiViewSubscriberTest extends TestCase
{
    public function testOnKernelViewUsesRealGameLifecycleStartMetadataAndMergesHeaders(): void
    {
        $gameDto = new GameResponseDto(id: 42, status: 'started', currentRound: 1, activePlayerId: null, currentThrowCount: 0, players: [], winnerId: null, settings: []);
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects($this->once())
            ->method('serialize')
            ->with($gameDto, 'json', [])
            ->willReturn('{"id":42,"status":"started"}');

        $subscriber = new ApiViewSubscriber($serializer);
        $request = Request::create('/api/game/42/start', 'POST');
        $request->attributes->set('_controller', GameLifecycleController::class.'::start');
        $request->attributes->set(ApiViewSubscriber::RESPONSE_HEADERS_REQUEST_ATTRIBUTE, [
            'ETag' => '"state-v1"',
            'Cache-Control' => 'private, no-cache',
            'X-Game-State-Version' => 'state-v1',
        ]);

        $event = new ViewEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $gameDto,
        );

        $subscriber->onKernelView($event);

        $response = $event->getResponse();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('"state-v1"', $response->headers->get('ETag'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-cache'));
        $this->assertSame('state-v1', $response->headers->get('X-Game-State-Version'));
        $this->assertSame('{"id":42,"status":"started"}', $response->getContent());
    }

    public function testOnKernelViewPassesRealApiResponseGroupsToSerializer(): void
    {
        $statsDto = new PlayerStatsResponseDto(limit: 20, offset: 0, total: 0, items: []);
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects($this->once())
            ->method('serialize')
            ->with($statsDto, 'json', ['groups' => ['stats:read']])
            ->willReturn('{"limit":20,"offset":0,"total":0,"items":[]}');

        $subscriber = new ApiViewSubscriber($serializer);
        $request = Request::create('/api/players/stats', 'GET');
        $request->attributes->set('_controller', GameStatsController::class.'::playerStats');

        $event = new ViewEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $statsDto,
        );

        $subscriber->onKernelView($event);

        $response = $event->getResponse();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('{"limit":20,"offset":0,"total":0,"items":[]}', $response->getContent());
    }

    public function testOnKernelViewLeavesAnIntegerStatusKeyInTheBodyAndTheHttpStatusAlone(): void
    {
        $data = ['success' => true, 'status' => 418];
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects($this->once())
            ->method('serialize')
            ->with($data, 'json', [])
            ->willReturn('{"success":true,"status":418}');
        $subscriber = new ApiViewSubscriber($serializer);
        $event = new ViewEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/api/anything', 'GET'),
            HttpKernelInterface::MAIN_REQUEST,
            $data,
        );

        $subscriber->onKernelView($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('{"success":true,"status":418}', $response->getContent());
    }
}
