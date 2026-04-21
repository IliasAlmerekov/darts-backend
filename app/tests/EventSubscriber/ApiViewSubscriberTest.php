<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Controller\GameLifecycleController;
use App\Entity\Game;
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
        $game = $this->createStub(Game::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects($this->once())
            ->method('serialize')
            ->with($game, 'json', ['groups' => ['game:read']])
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
            $game,
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
}
