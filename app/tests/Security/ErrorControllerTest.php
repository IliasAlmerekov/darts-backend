<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Exception\Game\PlayerAlreadyThrewThreeTimesException;
use App\Exception\Game\GameIdMissingException;
use App\Security\ErrorController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ErrorControllerTest extends TestCase
{
    public function testRendersApiExceptionAsJson(): void
    {
        $controller = new ErrorController();
        $response = $controller->show(new PlayerAlreadyThrewThreeTimesException());

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(false, $data['success'] ?? null);
        self::assertSame('GAME_PLAYER_THROWS_LIMIT_REACHED', $data['error'] ?? null);
    }

    public function testRendersGameIdMissingExceptionAsJson(): void
    {
        $controller = new ErrorController();
        $response = $controller->show(new GameIdMissingException());

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('GAME_ID_MISSING', $data['error'] ?? null);
    }

    public function testRendersNotFoundAsJson(): void
    {
        $controller = new ErrorController();
        $response = $controller->show(new NotFoundHttpException());

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('NOT_FOUND', $data['error'] ?? null);
    }

    public function testRendersGenericExceptionAsInternalServerError(): void
    {
        $controller = new ErrorController();
        $response = $controller->show(new \RuntimeException('boom'));

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('INTERNAL_SERVER_ERROR', $data['error'] ?? null);
    }
}
