<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Http\Attribute\ApiResponse;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Serializes controller return values to JSON for API requests.
 *
 * @psalm-suppress UnusedClass Registered via Symfony service container autoconfiguration
 */
final class ApiViewSubscriber implements EventSubscriberInterface
{
    /**
     * @param SerializerInterface $serializer
     */
    public function __construct(private readonly SerializerInterface $serializer)
    {
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::VIEW => 'onKernelView',
        ];
    }

    /**
     * @param ViewEvent $event
     *
     * @return void
     */
    public function onKernelView(ViewEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->isApiRequest($request)) {
            return;
        }

        $result = $event->getControllerResult();
        if ($result instanceof Response) {
            return;
        }

        $data = $result;
        $status = Response::HTTP_OK;
        $headers = [];
        $context = [];

        $apiResponse = $this->resolveApiResponseAttribute($request);
        if (null !== $apiResponse) {
            $status = $apiResponse->status;
            $headers = $apiResponse->headers;
            if ([] !== $apiResponse->groups) {
                $context['groups'] = $apiResponse->groups;
            }
        }

        if (is_array($data) && isset($data['status']) && is_int($data['status'])) {
            $status = $data['status'];
            unset($data['status']);
        }

        if (Response::HTTP_NO_CONTENT === $status) {
            $event->setResponse(new Response('', $status, $headers));

            return;
        }

        $json = $this->serializer->serialize($data, 'json', $context);
        $event->setResponse(new JsonResponse($json, $status, $headers, true));
    }

    /**
     * @param Request $request
     *
     * @return ApiResponse|null
     */
    private function resolveApiResponseAttribute(Request $request): ?ApiResponse
    {
        $controller = $request->attributes->get('_controller');
        if (!is_string($controller) || !str_contains($controller, '::')) {
            return null;
        }

        $parts = explode('::', $controller, 2);
        if (2 !== count($parts)) {
            return null;
        }
        [$class, $method] = $parts;
        if (!class_exists($class) || !method_exists($class, $method)) {
            return null;
        }

        try {
            $reflection = new \ReflectionMethod($class, $method);
        } catch (\ReflectionException) {
            return null;
        }

        $attributes = $reflection->getAttributes(ApiResponse::class);
        if ([] === $attributes) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    /**
     * @param Request $request
     *
     * @return bool
     */
    private function isApiRequest(Request $request): bool
    {
        $path = $request->getPathInfo();
        if (str_starts_with($path, '/api/')) {
            return true;
        }

        $accept = (string) $request->headers->get('Accept', '');

        return str_contains($accept, 'application/json') || 'json' === $request->getRequestFormat();
    }
}
