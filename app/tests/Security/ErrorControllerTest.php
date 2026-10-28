<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Exception\Game\PlayerAlreadyThrewThreeTimesException;
use App\Exception\Game\GameIdMissingException;
use App\Exception\Game\InvalidThrowException;
use App\Exception\Game\GamePlayerNotActiveException;
use App\Exception\Game\InvalidPlayerOrderException;
use App\Exception\Game\GameRoomFullException;
use App\Exception\Game\GameStartNotAllowedException;
use App\Enum\GameStatus;
use App\Security\ErrorController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validation;

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

    public function testRendersGameRoomFullExceptionAsJson(): void
    {
        $controller = new ErrorController();
        $response = $controller->show(new GameRoomFullException(10));

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('GAME_ROOM_FULL', $data['error'] ?? null);
        self::assertSame(
            'The room is already full (10 players). Please wait for an available spot.',
            $data['message'] ?? null
        );
    }

    public function testRendersGamePlayerNotActiveExceptionAsJson(): void
    {
        $controller = new ErrorController();
        $response = $controller->show(new GamePlayerNotActiveException(2, 1));

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('GAME_PLAYER_NOT_ACTIVE', $data['error'] ?? null);
    }

    public function testRendersInvalidThrowExceptionAsJson(): void
    {
        $controller = new ErrorController();
        $response = $controller->show(new InvalidThrowException('Invalid throw'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('GAME_INVALID_THROW', $data['error'] ?? null);
    }

    public function testRendersInvalidPlayerOrderExceptionAsJson(): void
    {
        $controller = new ErrorController();
        $response = $controller->show(new InvalidPlayerOrderException('Invalid order'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('GAME_INVALID_PLAYER_ORDER', $data['error'] ?? null);
    }

    public function testRendersGameStartNotAllowedExceptionAsJson(): void
    {
        $controller = new ErrorController();
        $response = $controller->show(new GameStartNotAllowedException(GameStatus::Started));

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('GAME_START_NOT_ALLOWED', $data['error'] ?? null);
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

    public function testRendersAPayloadCodeAndAClassLevelViolation(): void
    {
        $value = ['email' => 'nope'];
        $violations = Validation::createValidator()->validate($value, [
            new Assert\Collection(['email' => new Assert\Email(payload: ['code' => 'EMAIL_DOMAIN_NOT_ALLOWED'])]),
            new Assert\Expression('false', message: 'The body is inconsistent.'),
        ]);
        $exception = new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, 'joined messages', new ValidationFailedException($value, $violations));

        $response = (new ErrorController())->show($exception);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame(
            [
                'success' => false,
                'error' => 'REQUEST_VALIDATION_FAILED',
                'message' => 'The request body failed validation.',
                'violations' => [
                    ['field' => '[email]', 'code' => 'EMAIL_DOMAIN_NOT_ALLOWED', 'message' => 'This value is not a valid email address.'],
                    ['field' => '', 'code' => 'INVALID', 'message' => 'The body is inconsistent.'],
                ],
            ],
            json_decode((string) $response->getContent(), true),
        );
    }
}
